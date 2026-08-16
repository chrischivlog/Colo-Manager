<?php

declare(strict_types=1);

namespace ColoManager\Mail;

use ColoManager\Config;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\Mailer\Mailer;
use Symfony\Component\Mailer\Transport;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;

/** SMTP-Implementierung auf Basis des eigenständigen Symfony-Mailer-Pakets. */
final readonly class SymfonyMailSender implements MailSender
{
    private Mailer $mailer;

    public function __construct(private Config $config, private ?string $senderName = null)
    {
        // Die DSN enthält Host, Port, Verschlüsselung und optional Zugangsdaten.
        // Dadurch bleibt der Code unabhängig vom konkreten Mail-Anbieter.
        $this->mailer = new Mailer(Transport::fromDsn($config->mailerDsn));
    }

    public function send(MailMessage $message): void
    {
        $email = (new Email())
            ->from(new Address($this->config->mailFromAddress, $this->senderName ?: $this->config->mailFromName))
            ->to(...$message->recipients)
            ->subject($message->subject)
            ->html($message->html)
            ->text($message->text);

        if ($message->cc !== []) {
            $email->cc(...$message->cc);
        }
        if ($message->bcc !== []) {
            $email->bcc(...$message->bcc);
        }
        foreach ($message->attachments as $attachment) {
            $email->attach($attachment->content, $attachment->name, $attachment->mimeType);
        }

        $replyTo = $message->replyTo ?? $this->config->mailReplyTo;
        if ($replyTo !== null) {
            $email->replyTo($replyTo);
        }

        try {
            $this->mailer->send($email);
        } catch (TransportExceptionInterface $exception) {
            // SMTP-Details werden gekapselt, damit aufrufende Services später
            // einheitlich retryen oder einen Outbox-Status setzen können.
            throw new MailDeliveryException('Die E-Mail konnte nicht zugestellt werden.', previous: $exception);
        }
    }
}
