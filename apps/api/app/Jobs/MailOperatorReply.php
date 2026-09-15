<?php

namespace App\Jobs;

use App\Models\ChangeMessage;
use App\Services\ChangeChat;
use App\Services\CustomerMail;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Runs a quarter of an hour after a team reply in the change chat. A customer who had the thread
 * open since then saw it and gets no mail; one who did not gets one mail, for the newest reply
 * only, so three quick replies do not make three mails.
 */
class MailOperatorReply implements ShouldQueue
{
    use Queueable;

    public function __construct(public int $messageId) {}

    public function handle(CustomerMail $mail): void
    {
        $message = ChangeMessage::with('project.customer', 'project.order')->find($this->messageId);
        if ($message === null || $message->project === null) {
            return;
        }
        $project = $message->project;
        if (ChangeChat::lastSeen($project) >= $message->created_at->getTimestamp()) {
            return;
        }
        $later = $project->changeMessages()->where('id', '>', $message->id)->whereIn('role', ['operator', 'customer'])->exists();
        if ($later) {
            return; // a newer reply mails for itself, or the customer already answered
        }

        $mail->operatorReplied($project, $message->body);
    }
}
