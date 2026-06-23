<?php

// Copyright (c) ppy Pty Ltd <contact@ppy.sh>. Licensed under the GNU Affero General Public License v3.0.
// See the LICENCE file in the repository root for full licence text.

namespace Tests\Libraries\Mail;

use App\Libraries\Mail\BrevoTransport;
use Illuminate\Http\Client\Factory as HttpFactory;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\Mime\Email;

class BrevoTransportTest extends TestCase
{
    public function testSendsTextEmailViaBrevoApi(): void
    {
        $http = new HttpFactory();
        $http->fake([
            'https://api.example.test/smtp/email' => $http->response(['messageId' => 'message-id'], 201),
        ]);

        $transport = new BrevoTransport('test-api-key', 'https://api.example.test/smtp/email', $http);

        $transport->send((new Email())
            ->from('Sender <sender@example.com>')
            ->to('Recipient <recipient@example.com>')
            ->cc('Copy <copy@example.com>')
            ->subject('Test subject')
            ->text('Test body'));

        $http->assertSent(fn ($request) => $request->url() === 'https://api.example.test/smtp/email'
            && $request->hasHeader('api-key', 'test-api-key')
            && $request['sender'] === ['email' => 'sender@example.com', 'name' => 'Sender']
            && $request['to'] === [['email' => 'recipient@example.com', 'name' => 'Recipient']]
            && $request['cc'] === [['email' => 'copy@example.com', 'name' => 'Copy']]
            && $request['subject'] === 'Test subject'
            && $request['textContent'] === 'Test body');
    }

    public function testBrevoFailureThrows(): void
    {
        $http = new HttpFactory();
        $http->fake([
            'https://api.example.test/smtp/email' => $http->response('Unauthorized', 401),
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Brevo API returned HTTP 401');

        (new BrevoTransport('test-api-key', 'https://api.example.test/smtp/email', $http))
            ->send((new Email())
                ->from('sender@example.com')
                ->to('recipient@example.com')
                ->subject('Test subject')
                ->text('Test body'));
    }
}
