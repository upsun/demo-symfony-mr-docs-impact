<?php

namespace App\Service;

use App\Model\DocumentationImpact;
use App\Model\MergeRequest;
use Twig\Environment;

final readonly class CommentRenderer
{
    public function __construct(
        private Environment $twig,
    ) {}

    public function renderDocumentationImpact(
        DocumentationImpact $impact, 
        MergeRequest $mr,
        ?string $documentationGuidelinesUrl = null
    ): string {
        return $this->twig->render('comment/documentation_impact.md.twig', [
            'impact' => $impact,
            'mr' => $mr,
            'documentation_guidelines_url' => $documentationGuidelinesUrl,
        ]);
    }

    public function renderCodeExample(string $language, string $code, ?string $description = null): string
    {
        $template = $this->twig->createTemplate(
            '{% if description %}{{ description }}{% endif %}

```{{ language }}
{{ code }}
```'
        );

        return $template->render([
            'language' => $language,
            'code' => $code,
            'description' => $description,
        ]);
    }

    public function formatSuggestionWithCode(string $suggestion): string
    {
        // Enhanced markdown formatting for suggestions with code examples
        $suggestion = preg_replace_callback(
            '/```(\w+)?\n(.*?)\n```/s',
            function ($matches) {
                $language = $matches[1] ?: 'text';
                $code = trim($matches[2]);
                return "\n```{$language}\n{$code}\n```\n";
            },
            $suggestion
        );

        // Format inline code
        $suggestion = preg_replace('/`([^`]+)`/', '`$1`', $suggestion);

        // Format API endpoints
        $suggestion = preg_replace('/\b(GET|POST|PUT|DELETE|PATCH)\s+([\/\w\-\{\}]+)/', '**$1** `$2`', $suggestion);

        // Format environment variables
        $suggestion = preg_replace('/\b([A-Z_]+=[^\s]+)/', '`$1`', $suggestion);

        return $suggestion;
    }
}