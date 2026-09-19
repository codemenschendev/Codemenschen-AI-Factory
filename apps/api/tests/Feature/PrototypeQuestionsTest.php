<?php

namespace Tests\Feature;

use App\Domain\Ai\PrototypeQuestions;
use App\Models\Prototype;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/** The questions before the build, and the pictures a visitor uploads with the sentence. */
class PrototypeQuestionsTest extends TestCase
{
    use RefreshDatabase;

    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        $this->dir = sys_get_temp_dir().'/proto-uploads-'.bin2hex(random_bytes(4));
        config(['services.media.uploads_path' => $this->dir,
            'services.ai_image.base_url' => 'http://sidecar.test', 'services.ai_image.token' => 't']);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->dir);
        parent::tearDown();
    }

    public function test_questions_come_from_the_model_trimmed_to_three(): void
    {
        Http::fake(['sidecar.test/*' => Http::response(['choices' => [['message' => ['content' => "```json\n".json_encode(['questions' => [
            ['q' => 'Wen sollen die Anzeigen erreichen?', 'options' => ['Neukunden', 'Stammkunden', '', 'Firmen', 'Touristen', 'Alle']],
            ['q' => 'Was ist das Angebot?', 'options' => ['Rabatt', 'Neueröffnung']],
            ['q' => 'Welcher Ton?', 'options' => []],
            ['q' => 'Eine vierte?', 'options' => ['x']],
        ]])."\n```"]]]])]);

        $this->postJson('/api/prototypes/questions', ['prompt' => 'Eine Website für eine Bäckerei in Graz', 'kind' => 'site', 'locale' => 'de'])
            ->assertOk()
            ->assertJsonCount(3, 'questions')
            ->assertJsonPath('questions.0.options', ['Neukunden', 'Stammkunden', 'Firmen', 'Touristen']);
    }

    public function test_a_failed_call_asks_nothing_and_the_build_goes_ahead(): void
    {
        Http::fake(['sidecar.test/*' => Http::response('down', 500)]);

        $this->assertSame([], app(PrototypeQuestions::class)->ask('Eine Website für eine Bäckerei', 'site', 'de'));
        $this->assertSame([], PrototypeQuestions::parse('Sure, here are some questions.'));
    }

    public function test_answers_and_pictures_travel_with_the_prototype(): void
    {
        $this->post('/api/prototypes', [
            'prompt' => 'Eine Website für eine Bäckerei in Graz',
            'kind' => 'site',
            'details' => "Wen soll sie erreichen? Familien im Bezirk",
            'email' => 'b@example.com',
            'images' => [UploadedFile::fake()->image('Unser Brot.jpg', 800, 600), UploadedFile::fake()->image('logo.png', 200, 200)],
        ], ['Accept' => 'application/json'])->assertStatus(202);

        $proto = Prototype::sole();
        $this->assertStringEndsWith("Graz\n\nWen soll sie erreichen? Familien im Bezirk", $proto->prompt);
        $this->assertCount(2, $proto->uploads);
        $this->assertSame('Unser Brot', $proto->uploads[0]['name']);
        $this->assertFileExists($proto->uploads[0]['path']);
        $this->assertStringStartsWith($this->dir.'/prototypes/'.$proto->id.'/', $proto->uploads[1]['path']);
    }

    public function test_only_pictures_are_taken(): void
    {
        $this->post('/api/prototypes', [
            'prompt' => 'Eine Website für eine Bäckerei in Graz',
            'email' => 'b@example.com',
            'images' => [UploadedFile::fake()->create('menu.pdf', 100, 'application/pdf')],
        ], ['Accept' => 'application/json'])->assertStatus(422);
        $this->assertSame(0, Prototype::count());
    }
}
