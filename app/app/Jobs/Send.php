<?php

namespace App\Jobs;

use App\Models\Recipient;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\Mailer\Transport;
use Symfony\Component\Mime\Crypto\DkimSigner;
use Symfony\Component\Mime\Email;

class Send implements ShouldQueue, ShouldBeUniqueUntilProcessing
{
    use Queueable;

    /**
     * The number of seconds after which the job's unique lock will be released.
     *
     * @var int
     */
    public int $uniqueFor = 3600;

    /**
     * Get the unique ID for the job.
     */
    public function uniqueId(): string
    {
        return $this->batchId;
    }

    /**
     * Create a new job instance.
     */
    public function __construct(
        protected string $batchId
    )
    {
        //
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        $recipients = Recipient::where('batch_id', $this->batchId)->get();

        foreach ($recipients as $recipient) {
            $transport = Transport::fromDsn('smtp://mail@postfix:587');

            $signer = new DkimSigner(
                Storage::disk('dkim')->get($recipient->message->dkim_signer_domain . '.private'),
                $recipient->message->dkim_signer_domain,
                $recipient->message->dkim_signer_sector
            );

            $email = (new Email())
                ->from($recipient->message->from_title . " <{$recipient->message->from_email}>")
                ->to($recipient->email)
                ->returnPath('mail@' . $recipient->message->dkim_signer_domain)
                ->replyTo($recipient->message->replay_to)
                ->subject($this->subject($recipient))
                ->html($this->body($recipient));

            $this->headers($recipient, $email);
            $email = $signer->sign($email);


            try {
                $sentMessage = $transport->send($email);
            } catch (TransportExceptionInterface $e) {
                $recipient->status = 'failed';
                $recipient->debug = substr(str_replace(["\n", "\r"], ' ', $e->getMessage()), 0, 2000) . '...';
                $recipient->save();

                continue;
            }

            $recipient->postfix_id = $this->parseQueueId($sentMessage->getDebug());

            if (!$recipient->postfix_id) {
                $recipient->status = 'failed';
                $recipient->debug = substr(str_replace(["\n", "\r"], ' ', $sentMessage->getDebug()), 0, 2000) . '...';
            }

            $recipient->save();
        }
    }

    protected function parseQueueId(string $value): string|null
    {
        $regexp = '/queued as (\S+)/mis';
        $matches = [];
        if (preg_match($regexp, $value, $matches)) {
            return $matches[1];
        }

        return null;
    }

    protected function body(Recipient $recipient)
    {
        $body = $recipient->message->body;

        if (!$recipient->replacements) {
            return $body;
        }

        foreach ($recipient->replacements as $replacement) {
            $body = str_replace($replacement['search'], $replacement['replace'], $body);
        }

        return $body;
    }

    protected function headers(Recipient $recipient, Email $email): void
    {
        if (!$recipient->headers) {
            return;
        }

        foreach ($recipient->headers as $header) {
            $email->getHeaders()->addTextHeader($header['key'], $header['value']);
        }
    }

    protected function subject(Recipient $recipient)
    {
        $subject = $recipient->message->subject;

        if (!$recipient->replacements) {
            return $subject;
        }

        foreach ($recipient->replacements as $replacement) {
            $subject = str_replace($replacement['search'], $replacement['replace'], $subject);
        }

        return $subject;
    }
}
