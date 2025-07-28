<?php

namespace App\Prompt;

use App\Model\MergeRequest;

final readonly class AnalysisPromptBuilder
{
    public function build(MergeRequest $mr, string $diff): string
    {
        return <<<PROMPT
You are a documentation expert analyzing code changes to determine if user documentation needs to be updated.

Analyze the following merge request:
- Title: {$mr->title}
- Description: {$mr->description}
- Source Branch: {$mr->sourceBranch}
- Target Branch: {$mr->targetBranch}
- Author: {$mr->author}
- Changed Files: {$this->formatChangedFiles($mr->changedFiles)}

Diff content:
```
{$diff}
```

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

**High Impact Changes:**
- New API endpoints or significant API changes
- Breaking changes in public interfaces
- New features that users interact with
- Changes to configuration files or environment variables
- Database schema changes requiring migrations
- New CLI commands or significant parameter changes

**Medium Impact Changes:**
- Enhancements to existing features
- New optional configuration parameters
- Performance improvements users should know about
- Changes to error messages or logging
- Updates to third-party integrations

**Low Impact Changes:**
- Bug fixes that don't change user behavior
- Code style or formatting changes
- Internal refactoring without user impact
- Test-only changes
- Documentation-only changes

**No Impact Changes:**
- Internal code organization
- Comment updates
- Dependency updates without functional changes
- Build script improvements

Respond in JSON format:
{
    "requires_documentation": true/false,
    "impact_level": "none|low|medium|high|critical",
    "impacted_areas": ["user_guide", "api_docs", "configuration", "migration_guide", "cli_reference", "deployment"],
    "reasons": ["List of specific reasons why documentation is needed"],
    "suggestions": ["Specific documentation suggestions with examples or instructions"]
}

Focus on being practical and helpful. If documentation is needed, provide specific, actionable suggestions.
PROMPT;
    }

    private function formatChangedFiles(array $changedFiles): string
    {
        if (empty($changedFiles)) {
            return 'Not specified in merge request data';
        }

        return implode(', ', $changedFiles);
    }
}