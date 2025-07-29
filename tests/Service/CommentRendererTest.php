<?php

namespace App\Tests\Service;

use App\Model\DocumentationImpact;
use App\Model\ImpactLevel;
use App\Model\MergeRequest;
use App\Service\CommentRenderer;
use PHPUnit\Framework\TestCase;
use Twig\Environment;
use Twig\Loader\ArrayLoader;

class CommentRendererTest extends TestCase
{
    private CommentRenderer $renderer;
    private Environment $twig;

    protected function setUp(): void
    {
        $templates = [
            'comment/documentation_impact.md.twig' => '## {{ impact.level.emoji }} Documentation Impact Analysis

**Impact Level:** {{ impact.level.displayName }}  
**Documentation Required:** {{ impact.required ? \'Yes\' : \'No\' }}

{% if impact.impactedAreas is not empty %}
### 📋 Impacted Areas
{% for area in impact.impactedAreas %}
- {{ area|replace({\'_\': \' \'})|title }}
{% endfor %}

{% endif %}
{% if impact.reasons is not empty %}
### 🔍 Analysis Results
{% for reason in impact.reasons %}
- {{ reason }}
{% endfor %}

{% endif %}
{% if impact.suggestions is not empty %}
### 💡 Documentation Suggestions
{% for suggestion in impact.suggestions %}
{% if suggestion starts with \'```\' or \'`\' in suggestion %}
{{ suggestion|raw }}
{% else %}
- {{ suggestion }}
{% endif %}
{% endfor %}

{% endif %}
{% if impact.level.value in [\'high\', \'critical\'] %}
### ⚠️ Action Required
This change has **{{ impact.level.displayName|lower }}** documentation impact. Please prioritize updating the documentation before merging.

{% endif %}
---
*🤖 This analysis was generated automatically by the Documentation Impact Analyzer.*  
*Questions about this analysis? Check the [documentation guidelines]({{ documentation_guidelines_url|default(\'#\') }}) or contact the documentation team.*'
        ];

        $loader = new ArrayLoader($templates);
        $this->twig = new Environment($loader);
        $this->renderer = new CommentRenderer($this->twig);
    }

    public function testRenderHighImpactComment(): void
    {
        $impact = new DocumentationImpact(
            level: ImpactLevel::HIGH,
            required: true,
            impactedAreas: ['api_docs', 'user_guide'],
            reasons: ['New API endpoint added', 'Breaking change in response format'],
            suggestions: [
                'Document the new **POST** `/api/users` endpoint',
                'Update API authentication examples',
                'Add migration guide for breaking changes'
            ]
        );

        $mr = new MergeRequest(
            id: '123',
            title: 'Add user creation API',
            description: 'New API endpoint for user creation',
            sourceBranch: 'feature/user-api',
            targetBranch: 'main',
            author: 'developer',
            url: 'https://example.com/mr/123',
            changedFiles: ['src/Controller/UserController.php'],
            status: 'opened'
        );

        $result = $this->renderer->renderDocumentationImpact($impact, $mr);

        $this->assertStringContainsString('🔴 Documentation Impact Analysis', $result);
        $this->assertStringContainsString('**Impact Level:** High Impact', $result);
        $this->assertStringContainsString('**Documentation Required:** Yes', $result);
        $this->assertStringContainsString('### 📋 Impacted Areas', $result);
        $this->assertStringContainsString('- Api Docs', $result);
        $this->assertStringContainsString('- User Guide', $result);
        $this->assertStringContainsString('### 🔍 Analysis Results', $result);
        $this->assertStringContainsString('- New API endpoint added', $result);
        $this->assertStringContainsString('### 💡 Documentation Suggestions', $result);
        $this->assertStringContainsString('Document the new **POST** `/api/users` endpoint', $result);
        $this->assertStringContainsString('### ⚠️ Action Required', $result);
        $this->assertStringContainsString('This change has **high impact** documentation impact', $result);
    }

    public function testRenderLowImpactComment(): void
    {
        $impact = new DocumentationImpact(
            level: ImpactLevel::LOW,
            required: false,
            impactedAreas: [],
            reasons: ['Minor bug fix with no user-visible changes'],
            suggestions: []
        );

        $mr = new MergeRequest(
            id: '456',
            title: 'Fix typo in validation logic',
            description: 'Internal fix',
            sourceBranch: 'bugfix/typo',
            targetBranch: 'main',
            author: 'developer',
            url: 'https://example.com/mr/456',
            changedFiles: ['src/Service/ValidationService.php'],
            status: 'opened'
        );

        $result = $this->renderer->renderDocumentationImpact($impact, $mr);

        $this->assertStringContainsString('🟡 Documentation Impact Analysis', $result);
        $this->assertStringContainsString('**Documentation Required:** No', $result);
        $this->assertStringNotContainsString('### 📋 Impacted Areas', $result);
        $this->assertStringNotContainsString('### 💡 Documentation Suggestions', $result);
        $this->assertStringNotContainsString('### ⚠️ Action Required', $result);
    }

    public function testRenderCodeExample(): void
    {
        $code = 'function getUserById($id) {
    return User::find($id);
}';
        
        $result = $this->renderer->renderCodeExample('php', $code, 'Example function');

        $this->assertStringContainsString('Example function', $result);
        $this->assertStringContainsString('```php', $result);
        $this->assertStringContainsString('function getUserById($id)', $result);
        $this->assertStringContainsString('```', $result);
    }

    public function testFormatSuggestionWithCode(): void
    {
        $suggestion = 'Document the new GET /api/users endpoint with this example:

```json
{
  "id": 1,
  "name": "John Doe"
}
```';

        $result = $this->renderer->formatSuggestionWithCode($suggestion);

        $this->assertStringContainsString('**GET** `/api/users`', $result);
        $this->assertStringContainsString('```json', $result);
        $this->assertStringContainsString('"id": 1', $result);
    }

    public function testFormatEnvironmentVariables(): void
    {
        $suggestion = 'Add DATABASE_URL=postgresql://user:pass@localhost/db to your .env file';

        $result = $this->renderer->formatSuggestionWithCode($suggestion);

        $this->assertStringContainsString('`DATABASE_URL=postgresql://user:pass@localhost/db`', $result);
    }
}