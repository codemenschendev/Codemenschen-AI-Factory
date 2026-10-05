<?php

namespace Tests\Feature;

/** A reported fault is fixed free under the warranty, without using a round, Care or credits. */
class WarrantyFixTest extends PluginFeatureTest
{
    public function test_a_reported_fault_is_fixed_for_free(): void
    {
        $project = $this->plugin();
        $this->replies = [['reply' => 'Thanks, that is a fault. We fix it free of charge.', 'scope' => 'bug',
            'items' => [['text' => 'Saving the FAQ settings keeps the entered questions']]]];
        $res = $this->withHeaders($this->as($project))->postJson("/api/me/projects/{$project->id}/messages", ['body' => 'Saving deletes my questions'])->assertCreated();
        $this->assertSame('warranty', $res->json('messages.1.meta.card.mode'));
        $this->assertSame(0, $res->json('messages.1.meta.card.price_eur'));

        $this->withHeaders($this->as($project))->postJson("/api/me/projects/{$project->id}/messages/confirm", ['fagg_waiver' => false])->assertSuccessful();
        $cr = $project->changeRequests()->firstOrFail();
        $this->assertSame('warranty', $cr->covered_by);
        $this->assertSame(0, $cr->price_eur);
        $this->assertSame('in_progress', $cr->status);
    }
}
