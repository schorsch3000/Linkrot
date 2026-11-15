<?php

declare(strict_types=1);

namespace Linkrot\Mail;

/** Sends one plain text mail. */
interface Mailer
{
    /** @throws MailFailed if the mail could not be handed over */
    public function send(string $to, string $subject, string $body): void;
}
