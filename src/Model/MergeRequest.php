<?php

namespace App\Model;

final readonly class MergeRequest
{
    public function __construct(
        public string $id,
        public string $title,
        public string $description,
        public string $sourceBranch,
        public string $targetBranch,
        public string $author,
        public string $url,
        public array $changedFiles,
        public string $status,
        public ?string $diffUrl = null,
    ) {}

    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'description' => $this->description,
            'source_branch' => $this->sourceBranch,
            'target_branch' => $this->targetBranch,
            'author' => $this->author,
            'url' => $this->url,
            'changed_files' => $this->changedFiles,
            'status' => $this->status,
            'diff_url' => $this->diffUrl,
        ];
    }

    public function isDraftOrWip(): bool
    {
        return str_contains(strtolower($this->title), 'draft') ||
               str_contains(strtolower($this->title), 'wip') ||
               str_contains(strtolower($this->title), 'work in progress') ||
               $this->status === 'draft';
    }

    public function isClosed(): bool
    {
        return in_array($this->status, ['closed', 'merged'], true);
    }
}