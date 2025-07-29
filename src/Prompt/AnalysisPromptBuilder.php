<?php

namespace App\Prompt;

use App\Model\MergeRequest;

final readonly class AnalysisPromptBuilder
{
    public function build(MergeRequest $mr, string $diff): string
    {
        $fileTypeAnalysis = $this->analyzeFileTypes($mr->changedFiles, $diff);
        $examples = $this->getRelevantExamples($fileTypeAnalysis);
        
        return <<<PROMPT
You are a documentation expert analyzing code changes to determine if user documentation needs to be updated.

Analyze the following merge request:
- Title: {$mr->title}
- Description: {$mr->description}
- Source Branch: {$mr->sourceBranch}
- Target Branch: {$mr->targetBranch}
- Author: {$mr->author}
- Changed Files: {$this->formatChangedFiles($mr->changedFiles)}

{$fileTypeAnalysis}

Diff content:
```
{$diff}
```

{$examples}

Determine if this change requires documentation updates by analyzing:
1. Does it change user-facing functionality?
2. Does it add/modify/remove API endpoints?
3. Does it change configuration options?
4. Does it introduce breaking changes?
5. Does it affect performance in ways users should know?
6. Does it change CLI commands or parameters?
7. Does it modify database schema or migrations?
8. Does it change environment variables or deployment requirements?

Consider these change types and their documentation impact:

**Critical Impact Changes:**
- Breaking changes that require immediate user action
- Security vulnerabilities or fixes affecting user behavior
- Complete feature removals or major API overhauls
- Changes requiring data migration or system downtime

**High Impact Changes:**
- New API endpoints or significant API changes
- New features that users interact with
- Changes to configuration files or environment variables
- Database schema changes requiring migrations
- New CLI commands or significant parameter changes
- Changes to authentication or authorization

**Medium Impact Changes:**
- Enhancements to existing features
- New optional configuration parameters
- Performance improvements users should know about
- Changes to error messages or logging
- Updates to third-party integrations
- UI/UX improvements or changes

**Low Impact Changes:**
- Bug fixes that don't change user behavior
- Minor performance optimizations
- Internal API improvements without breaking changes
- Logging improvements
- Minor configuration additions

**No Impact Changes:**
- Internal code organization and refactoring
- Comment updates
- Test-only changes
- Build script improvements
- Code style or formatting changes
- Documentation-only changes
- Dependency updates without functional changes

Respond in JSON format:
{
    "requires_documentation": true/false,
    "impact_level": "none|low|medium|high|critical",
    "impacted_areas": ["user_guide", "api_docs", "configuration", "migration_guide", "cli_reference", "deployment"],
    "reasons": ["List of specific reasons why documentation is needed"],
    "suggestions": ["Specific documentation suggestions with examples or instructions"]
}

Focus on being practical and helpful. If documentation is needed, provide specific, actionable suggestions with code examples when appropriate.
PROMPT;
    }

    private function analyzeFileTypes(array $changedFiles, string $diff): string
    {
        if (empty($changedFiles)) {
            // Extract file paths from diff if changedFiles is empty
            $changedFiles = $this->extractFilesFromDiff($diff);
        }

        $analysis = "**File Type Analysis:**\n";
        $categories = [
            'controllers' => [],
            'models' => [],
            'config' => [],
            'templates' => [],
            'migrations' => [],
            'api' => [],
            'cli' => [],
            'tests' => [],
            'other' => []
        ];

        foreach ($changedFiles as $file) {
            $category = $this->categorizeFile($file);
            $categories[$category][] = $file;
        }

        foreach ($categories as $category => $files) {
            if (!empty($files)) {
                $analysis .= "- " . ucfirst($category) . ": " . implode(', ', $files) . "\n";
            }
        }

        return $analysis;
    }

    private function categorizeFile(string $file): string
    {
        $file = strtolower($file);
        
        if (strpos($file, 'controller') !== false || strpos($file, '/controllers/') !== false) {
            return 'controllers';
        }
        if (strpos($file, 'model') !== false || strpos($file, '/models/') !== false || 
            strpos($file, 'entity') !== false || strpos($file, '/entities/') !== false) {
            return 'models';
        }
        if (strpos($file, 'config') !== false || strpos($file, '.yaml') !== false || 
            strpos($file, '.yml') !== false || strpos($file, '.json') !== false ||
            strpos($file, '.env') !== false) {
            return 'config';
        }
        if (strpos($file, 'template') !== false || strpos($file, '.twig') !== false || 
            strpos($file, '.html') !== false) {
            return 'templates';
        }
        if (strpos($file, 'migration') !== false || strpos($file, '/migrations/') !== false) {
            return 'migrations';
        }
        if (strpos($file, '/api/') !== false || strpos($file, 'apicontroller') !== false) {
            return 'api';
        }
        if (strpos($file, 'command') !== false || strpos($file, '/commands/') !== false ||
            strpos($file, 'console') !== false) {
            return 'cli';
        }
        if (strpos($file, 'test') !== false || strpos($file, '/tests/') !== false) {
            return 'tests';
        }
        
        return 'other';
    }

    private function extractFilesFromDiff(string $diff): array
    {
        $files = [];
        $lines = explode("\n", $diff);
        
        foreach ($lines as $line) {
            if (preg_match('/^(\+\+\+|---) [ab]\/(.+)$/', $line, $matches)) {
                $files[] = $matches[2];
            }
        }
        
        return array_unique($files);
    }

    private function getRelevantExamples(string $fileTypeAnalysis): string
    {
        $examples = "**Examples of Documentation Impact:**\n\n";
        
        if (strpos($fileTypeAnalysis, 'Controllers:') !== false) {
            $examples .= $this->getControllerExamples();
        }
        if (strpos($fileTypeAnalysis, 'Models:') !== false) {
            $examples .= $this->getModelExamples();
        }
        if (strpos($fileTypeAnalysis, 'Config:') !== false) {
            $examples .= $this->getConfigExamples();
        }
        if (strpos($fileTypeAnalysis, 'Templates:') !== false) {
            $examples .= $this->getTemplateExamples();
        }
        if (strpos($fileTypeAnalysis, 'Migrations:') !== false) {
            $examples .= $this->getMigrationExamples();
        }
        if (strpos($fileTypeAnalysis, 'Api:') !== false) {
            $examples .= $this->getApiExamples();
        }
        if (strpos($fileTypeAnalysis, 'Cli:') !== false) {
            $examples .= $this->getCliExamples();
        }

        return $examples;
    }

    private function getControllerExamples(): string
    {
        return <<<EXAMPLES
**Controller Changes:**
- New action methods → Document new pages/features
- Route changes → Update URL references in docs
- New form handling → Document form submission process
- Authentication changes → Update security documentation

EXAMPLES;
    }

    private function getModelExamples(): string
    {
        return <<<EXAMPLES
**Model/Entity Changes:**
- New fields → Document data structure changes
- Validation changes → Update validation rules documentation
- Relationship changes → Document new associations
- Method additions → Document new business logic

EXAMPLES;
    }

    private function getConfigExamples(): string
    {
        return <<<EXAMPLES
**Configuration Changes:**
- New environment variables → Document required settings
- Config parameter changes → Update configuration guide
- Service definitions → Document new services/dependencies
- Route configurations → Update routing documentation

EXAMPLES;
    }

    private function getTemplateExamples(): string
    {
        return <<<EXAMPLES
**Template Changes:**
- New UI elements → Screenshot updates needed
- Form changes → Update user interaction guides
- Layout modifications → Update UI documentation
- New template variables → Document template context

EXAMPLES;
    }

    private function getMigrationExamples(): string
    {
        return <<<EXAMPLES
**Migration Changes:**
- Database schema changes → Update database documentation
- New tables → Document new data structures
- Column modifications → Update field references
- Index changes → Document performance implications

EXAMPLES;
    }

    private function getApiExamples(): string
    {
        return <<<EXAMPLES
**API Changes:**
- New endpoints → Document API reference
- Parameter changes → Update request/response examples
- Authentication changes → Update API authentication docs
- Response format changes → Update integration guides

EXAMPLES;
    }

    private function getCliExamples(): string
    {
        return <<<EXAMPLES
**CLI Changes:**
- New commands → Document command usage
- Parameter changes → Update command reference
- Output format changes → Update example outputs
- New options → Document available flags

EXAMPLES;
    }

    private function formatChangedFiles(array $changedFiles): string
    {
        if (empty($changedFiles)) {
            return 'Not specified in merge request data';
        }

        return implode(', ', $changedFiles);
    }
}