<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class RoughEstimateTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['services.ai_image.base_url' => 'http://model.test', 'services.ai_image.token' => 't']);
    }

    private function fakeModel(string $content): void
    {
        Http::fake(['model.test/*' => Http::response(['choices' => [['message' => ['content' => $content]]]])]);
    }

    public function test_the_idea_comes_back_as_priced_parts(): void
    {
        $this->fakeModel("```json\n".json_encode(['parts' => [
            ['name' => 'Checkboxes — to tick off tasks', 'eur' => 14],
            ['name' => 'Saving the items', 'eur' => 5],
            ['name' => 'Release in the stores', 'eur' => 15],
            ['name' => 'Rocket', 'eur' => 9000],
            ['name' => '', 'eur' => 10],
        ]])."\n```");

        $this->postJson('/api/estimate/rough', ['text' => 'A todo app where I tick off my tasks', 'locale' => 'en'])
            ->assertOk()
            ->assertJsonPath('parts.0', ['name' => 'Checkboxes, to tick off tasks', 'eur' => 15])
            ->assertJsonPath('parts.3.eur', 100)
            ->assertJsonCount(4, 'parts')
            ->assertJsonPath('total', 135);
    }

    public function test_the_same_text_is_asked_once(): void
    {
        $this->fakeModel(json_encode(['parts' => [['name' => 'List', 'eur' => 10]]]));
        $this->postJson('/api/estimate/rough', ['text' => 'A todo app where I tick off my tasks'])->assertOk();
        $this->postJson('/api/estimate/rough', ['text' => 'a todo app where  I tick off my tasks '])->assertOk();
        Http::assertSentCount(1);
    }

    public function test_a_broken_reply_or_short_text_gives_no_price(): void
    {
        $this->fakeModel('sorry');
        $this->postJson('/api/estimate/rough', ['text' => 'A todo app where I tick off my tasks'])->assertStatus(503);
        $this->postJson('/api/estimate/rough', ['text' => 'todo'])->assertStatus(422);
    }
}
