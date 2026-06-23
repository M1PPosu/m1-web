<?php

// Copyright (c) ppy Pty Ltd <contact@ppy.sh>. Licensed under the GNU Affero General Public License v3.0.
// See the LICENCE file in the repository root for full licence text.

namespace App\Libraries\Mail;

use Illuminate\Http\Client\Factory as HttpFactory;
use RuntimeException;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\AbstractTransport;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;

class BrevoTransport extends AbstractTransport
{
    public function __construct(
        private readonly ?string $apiKey,
        private readonly string $endpoint,
        private ?HttpFactory $http = null,
    ) {
        parent::__construct();
    }

    protected function doSend(SentMessage $message): void
    {
        if ($this->apiKey === null || trim($this->apiKey) === '') {
            throw new RuntimeException('BREVO_API_KEY is not configured.');
        }

        $originalMessage = $message->getOriginalMessage();
        if (!$originalMessage instanceof Email) {
            throw new RuntimeException('Brevo transport only supports Symfony Email messages.');
        }

        $response = $this->http()->acceptJson()
            ->asJson()
            ->timeout(20)
            ->withHeaders(['api-key' => $this->apiKey])
            ->post($this->endpoint, $this->payload($originalMessage));

        if ($response->failed()) {
            throw new RuntimeException("Brevo API returned HTTP {$response->status()}: {$response->body()}");
        }
    }

    public function __toString(): string
    {
        return 'brevo';
    }

    private function payload(Email $email): array
    {
        if ($email->getAttachments() !== []) {
            throw new RuntimeException('Brevo transport does not support attachments.');
        }

        $from = $email->getFrom()[0] ?? null;
        if (!$from instanceof Address) {
            throw new RuntimeException('Email sender is missing.');
        }

        $payload = [
            'sender' => $this->address($from),
            'to' => array_map(fn (Address $address) => $this->address($address), $email->getTo()),
            'subject' => $email->getSubject() ?? '',
        ];

        if ($payload['to'] === []) {
            throw new RuntimeException('Email recipient is missing.');
        }

        if ($email->getCc() !== []) {
            $payload['cc'] = array_map(fn (Address $address) => $this->address($address), $email->getCc());
        }

        if ($email->getBcc() !== []) {
            $payload['bcc'] = array_map(fn (Address $address) => $this->address($address), $email->getBcc());
        }

        if (($replyTo = $email->getReplyTo()[0] ?? null) instanceof Address) {
            $payload['replyTo'] = $this->address($replyTo);
        }

        if ($email->getHtmlBody() !== null) {
            $payload['htmlContent'] = $email->getHtmlBody();
        }

        if ($email->getTextBody() !== null) {
            $payload['textContent'] = $email->getTextBody();
        }

        if (!isset($payload['htmlContent']) && !isset($payload['textContent'])) {
            $payload['textContent'] = '';
        }

        return $payload;
    }

    private function address(Address $address): array
    {
        $payload = ['email' => $address->getAddress()];

        if ($address->getName() !== '') {
            $payload['name'] = $address->getName();
        }

        return $payload;
    }

    private function http(): HttpFactory
    {
        return $this->http ??= new HttpFactory();
    }
}
