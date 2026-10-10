<?php

namespace App\Notifications;

use App\Models\Comment;
use App\Models\Transaction;
use Illuminate\Support\Str;

class LineQueriedNotification extends CardTrailNotification
{
    public function __construct(public Transaction $line, public Comment $comment) {}

    protected function subject(): string
    {
        return 'Accounts have a question about '.Str::limit($this->line->description, 60);
    }

    protected function lines(): array
    {
        return [
            '“'.Str::limit($this->comment->body, 300).'”',
            'Row '.$this->line->row_number.' · '.$this->line->transacted_on->format('j M Y').' · £'.number_format(abs($this->line->amount) / 100, 2).($this->line->amount < 0 ? ' credit' : ''),
            'Reply or update the line, then submit the month again.',
        ];
    }

    protected function url(): string
    {
        return route('transactions.show', $this->line);
    }

    protected function action(): string
    {
        return 'Answer the question';
    }
}
