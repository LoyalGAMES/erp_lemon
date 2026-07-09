<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Mail\CustomerMessageMail;
use App\Models\AppSetting;
use App\Models\CustomerMessage;
use App\Models\EmailTemplate;
use App\Services\Communication\MailSettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class MailSettingsWorkflowTest extends TestCase
{
    use RefreshDatabase;

    public function test_mail_settings_can_be_saved_and_applied(): void
    {
        $this->get(route('settings.mail'))
            ->assertOk()
            ->assertSee('Ustawienia maili')
            ->assertSee('SMTP');

        $this->put(route('settings.mail.update'), [
            'enabled' => '1',
            'host' => 'smtp.example.test',
            'port' => 587,
            'encryption' => 'tls',
            'username' => 'mailer@example.test',
            'password' => 'secret-password',
            'from_address' => 'sklep@example.test',
            'from_name' => 'Sempre Sklep',
            'ehlo_domain' => 'erp.example.test',
            'timeout' => 20,
        ])->assertRedirect()->assertSessionHas('status');

        $setting = AppSetting::query()->where('key', 'mail_settings')->firstOrFail();
        $this->assertSame('smtp.example.test', $setting->value['host']);
        $this->assertNotSame('secret-password', $setting->value['password_encrypted']);
        $this->assertSame('secret-password', Crypt::decryptString($setting->value['password_encrypted']));

        $mailSettings = app(MailSettingsService::class);
        $this->assertTrue($mailSettings->data()['password_configured']);
        $this->assertTrue($mailSettings->apply());

        $this->assertSame('smtp', config('mail.default'));
        $this->assertSame('smtp.example.test', config('mail.mailers.smtp.host'));
        $this->assertSame(587, config('mail.mailers.smtp.port'));
        $this->assertSame('smtp', config('mail.mailers.smtp.scheme'));
        $this->assertSame('sklep@example.test', config('mail.from.address'));
        $this->assertSame('Sempre Sklep', config('mail.from.name'));
    }

    public function test_mail_settings_test_message_uses_saved_configuration(): void
    {
        Mail::fake();

        app(MailSettingsService::class)->update([
            'enabled' => true,
            'host' => 'smtp.example.test',
            'port' => 465,
            'encryption' => 'ssl',
            'username' => 'mailer@example.test',
            'password' => 'secret-password',
            'from_address' => 'sklep@example.test',
            'from_name' => 'Sempre Sklep',
            'timeout' => 15,
        ]);

        $this->post(route('settings.mail.test'), [
            'recipient' => 'admin@example.test',
        ])->assertRedirect()->assertSessionHas('status');

        $this->assertSame('smtps', config('mail.mailers.smtp.scheme'));
    }

    public function test_email_templates_can_be_managed_from_mail_settings(): void
    {
        $this->get(route('settings.mail'))
            ->assertOk()
            ->assertSee('Szablony e-mail');

        $this->post(route('settings.mail.templates.store'), [
            'name' => 'Brak towaru test',
            'context' => 'order',
            'subject' => 'Brak towaru {{order_number}}',
            'body' => 'Dzień dobry, produkt jest niedostępny.',
            'is_active' => '1',
        ])->assertRedirect()->assertSessionHas('status');

        $template = EmailTemplate::query()->where('name', 'Brak towaru test')->firstOrFail();
        $this->assertSame('order', $template->context);
        $this->assertTrue($template->is_active);

        $this->put(route('settings.mail.templates.update', $template), [
            'name' => 'Brak towaru - poprawka',
            'context' => 'both',
            'subject' => 'Aktualizacja {{order_number}}',
            'body' => 'Nowa treść szablonu.',
        ])->assertRedirect()->assertSessionHas('status');

        $template->refresh();
        $this->assertSame('Brak towaru - poprawka', $template->name);
        $this->assertSame('both', $template->context);
        $this->assertFalse($template->is_active);

        $this->delete(route('settings.mail.templates.destroy', $template))
            ->assertRedirect()
            ->assertSessionHas('status');

        $this->assertDatabaseMissing('email_templates', [
            'id' => $template->id,
        ]);
    }

    public function test_customer_mail_layout_can_be_configured_and_rendered(): void
    {
        $this->put(route('settings.mail.update'), [
            'enabled' => '1',
            'host' => 'smtp.example.test',
            'port' => 587,
            'encryption' => 'tls',
            'username' => 'mailer@example.test',
            'from_address' => 'sklep@example.test',
            'from_name' => 'Sempre Sklep',
            'timeout' => 20,
            'brand_name' => 'Sempre Premium',
            'logo_url' => 'https://cdn.example.test/logo.png',
            'accent_color' => '#1f7a53',
            'header_text' => 'Obsługa klienta Sempre',
            'signature' => "Pozdrawiamy,\nZespół BOK",
            'footer_text' => 'Sempre WL - wiadomość systemowa.',
            'support_email' => 'bok@example.test',
            'support_phone' => '+48 123 456 789',
        ])->assertRedirect()->assertSessionHas('status');

        $settings = app(MailSettingsService::class)->data();
        $this->assertSame('Sempre Premium', $settings['brand_name']);
        $this->assertSame('#1f7a53', $settings['accent_color']);
        $this->assertSame('bok@example.test', $settings['support_email']);

        $message = CustomerMessage::query()->create([
            'direction' => 'outgoing',
            'type' => 'automated',
            'trigger' => 'exchange_payment_requested',
            'status' => 'pending',
            'recipient_email' => 'client@example.test',
            'subject' => 'Dopłata do wymiany {{return_number}}',
            'body' => 'Prosimy o opłacenie dopłaty {{payment_url}}.',
            'metadata' => [
                'return_number' => 'RET/1',
                'payment_url' => 'https://pay.example.test/ret/1',
            ],
        ]);

        $html = (new CustomerMessageMail($message))->render();

        $this->assertStringContainsString('Sempre Premium', $html);
        $this->assertStringContainsString('https://cdn.example.test/logo.png', $html);
        $this->assertStringContainsString('Obsługa klienta Sempre', $html);
        $this->assertStringContainsString('Dopłata do wymiany RET/1', $html);
        $this->assertStringNotContainsString('{{return_number}}', $html);
        $this->assertStringContainsString('Przejdź do płatności', $html);
        $this->assertStringContainsString('https://pay.example.test/ret/1', $html);
        $this->assertStringContainsString('Sempre WL - wiadomość systemowa.', $html);
        $this->assertStringContainsString('bok@example.test', $html);

        $mailable = new CustomerMessageMail($message);
        $mailable->build();

        $this->assertSame('Dopłata do wymiany RET/1', $mailable->subject);
        $this->assertSame('emails.customer-message-text', $mailable->textView);
        $this->assertEquals([['address' => 'sklep@example.test', 'name' => 'Sempre Sklep']], $mailable->from);
        $this->assertEquals([['address' => 'bok@example.test', 'name' => 'Sempre Premium']], $mailable->replyTo);
    }

    public function test_mail_settings_page_shows_deliverability_hints_and_template_variables(): void
    {
        app(MailSettingsService::class)->update([
            'enabled' => true,
            'host' => 'smtp.mail-provider.test',
            'port' => 587,
            'encryption' => 'tls',
            'username' => 'smtp-user@provider.test',
            'from_address' => 'powiadomienia@semprelove.pl',
            'from_name' => 'Sempre with Love',
            'ehlo_domain' => 'erp.semprelove.pl',
            'timeout' => 15,
        ]);

        $this->get(route('settings.mail'))
            ->assertOk()
            ->assertSee('Dostarczalność')
            ->assertSee('Workflow maili do klientów')
            ->assertSee('order_created')
            ->assertSee('return_refunded')
            ->assertSee('Wysyłaj do klienta')
            ->assertSee('DNS domeny semprelove.pl')
            ->assertSee('Login SMTP jest w innej domenie')
            ->assertSee('{{return_number}}')
            ->assertSee('{{order_number}}');
    }

    public function test_customer_email_workflow_can_be_configured_from_mail_settings(): void
    {
        $this->put(route('settings.mail.workflow.update'), [
            'workflow' => [
                'order_received' => [
                    'enabled' => '0',
                    'stage' => 'Po płatności - ręczna decyzja',
                    'subject' => 'Nie wysyłaj {{order_number}}',
                    'body' => 'Ten mail jest wyłączony.',
                ],
                'order_packed' => [
                    'enabled' => '1',
                    'stage' => 'Po pakowaniu',
                    'subject' => 'Spakowaliśmy {{order_number}}',
                    'body' => 'Paczka {{order_number}} czeka na kuriera.',
                ],
            ],
        ])->assertRedirect()->assertSessionHas('status');

        $setting = AppSetting::query()->where('key', 'customer_email_workflow')->firstOrFail();

        $this->assertFalse($setting->value['order_received']['enabled']);
        $this->assertSame('Po płatności - ręczna decyzja', $setting->value['order_received']['stage']);
        $this->assertSame('Spakowaliśmy {{order_number}}', $setting->value['order_packed']['subject']);
        $this->assertSame('Paczka {{order_number}} czeka na kuriera.', $setting->value['order_packed']['body']);
    }
}
