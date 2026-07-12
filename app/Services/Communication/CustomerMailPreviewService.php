<?php

declare(strict_types=1);

namespace App\Services\Communication;

use App\Models\CustomerMessage;

final class CustomerMailPreviewService
{
    public function __construct(
        private readonly CustomerMailContextService $context,
        private readonly CustomerMailPresentationService $presentation,
        private readonly EmailTemplateRenderer $renderer,
    ) {}

    /**
     * @param  array<string, mixed>  $layout
     * @return array{subject:string,html:string,text:string,diagnostics:array{unknown_variables:list<string>,missing_variables:list<string>}}
     */
    public function render(
        string $trigger,
        string $scenario,
        string $subject,
        string $body,
        array $layout = [],
    ): array {
        $metadata = $this->context->previewContext($scenario, $trigger);
        $message = new CustomerMessage([
            'direction' => 'outgoing',
            'type' => 'automated',
            'trigger' => $trigger,
            'status' => 'preview',
            'recipient_email' => $metadata['customer_email'] ?? 'anna.kowalska@example.com',
            'recipient_name' => $metadata['customer_name'] ?? 'Anna Kowalska',
            'subject' => $subject,
            'body' => $body,
            'metadata' => $metadata,
        ]);

        $diagnostics = $this->diagnostics($subject."\n".$body, $metadata);

        return [
            'subject' => $message->renderedSubject(),
            'html' => $this->presentation->html($message, $layout),
            'text' => $this->presentation->text($message, $layout),
            'diagnostics' => $diagnostics,
        ];
    }

    /**
     * @param  array<string, mixed>  $context
     * @return array{unknown_variables:list<string>,missing_variables:list<string>}
     */
    private function diagnostics(string $template, array $context): array
    {
        preg_match_all('/\{\{\s*([a-zA-Z0-9_]+)\s*\}\}/', $template, $matches);
        $used = array_values(array_unique($matches[1] ?? []));
        $known = array_keys($this->renderer->variables());
        $unknown = array_values(array_diff($used, $known));
        $missing = array_values(array_filter(
            array_intersect($used, $known),
            fn (string $variable): bool => blank($context[$variable] ?? null),
        ));

        sort($unknown);
        sort($missing);

        return [
            'unknown_variables' => $unknown,
            'missing_variables' => $missing,
        ];
    }
}
