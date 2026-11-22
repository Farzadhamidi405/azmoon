<?php

namespace Tests\Feature\Api\V1;

use App\Models\Media;
use App\Models\Passage;
use App\Models\Question;
use App\Models\QuestionOption;
use App\Models\QuestionVersion;
use App\Models\Role;
use App\Models\Subject;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * QuestionCreationTest — تست‌های متمرکز و کامل برای ایجاد انواع سوالات در بانک سوالات.
 */
class QuestionCreationTest extends TestCase
{
    use RefreshDatabase;

    private User $questionManager;
    private User $labManager;
    private Subject $subject;

    protected function setUp(): void
    {
        parent::setUp();

        $qmRole = Role::create(['name' => 'Question Manager', 'is_system_role' => true]);
        $lmRole = Role::create(['name' => 'Lab Manager', 'is_system_role' => true]);

        $this->questionManager = User::factory()->create(['role_id' => $qmRole->id]);
        $this->labManager      = User::factory()->create(['role_id' => $lmRole->id]);

        $this->subject = Subject::create([
            'name'       => 'English Language',
            'code'       => 'ENG101',
            'is_active'  => true,
            'created_by' => $this->questionManager->id,
        ]);
    }

    #[Test]
    public function can_create_single_choice_question(): void
    {
        $payload = [
            'subject_id'    => $this->subject->id,
            'section_name'  => 'Grammar',
            'question_type' => 'single',
            'question_text' => 'Which sentence is grammatically correct?',
            'difficulty'    => 'medium',
            'topic'         => 'Conditionals',
            'score'         => 2.5,
            'options'       => [
                ['option_text' => 'If I was you, I go.', 'is_correct' => false, 'display_order' => 0],
                ['option_text' => 'If I were you, I would go.', 'is_correct' => true, 'display_order' => 1],
                ['option_text' => 'If I am you, I will went.', 'is_correct' => false, 'display_order' => 2],
                ['option_text' => 'If I had been you, I go.', 'is_correct' => false, 'display_order' => 3],
            ],
        ];

        $response = $this->actingAs($this->questionManager, 'admin')
            ->postJson('/api/v1/admin/questions', $payload);

        $response->assertCreated()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.question_text', $payload['question_text'])
            ->assertJsonPath('data.section_name', 'Grammar')
            ->assertJsonPath('data.difficulty', 'medium')
            ->assertJsonPath('data.status', 'draft')
            ->assertJsonCount(4, 'data.options');

        $questionId = $response->json('data.id');

        $this->assertDatabaseHas('questions', [
            'id'            => $questionId,
            'subject_id'    => $this->subject->id,
            'question_type' => 'single',
            'score'         => 2.5,
            'status'        => 'draft',
        ]);

        $this->assertDatabaseCount('question_options', 4);

        $question = Question::find($questionId);
        $this->assertNotNull($question->current_version_id);

        $version = QuestionVersion::find($question->current_version_id);
        $this->assertNotNull($version);
        $this->assertEquals(1, $version->version_number);
        $this->assertCount(4, $version->options_snapshot);
    }

    #[Test]
    public function can_create_multiple_choice_question_with_multiple_correct_answers(): void
    {
        $payload = [
            'subject_id'    => $this->subject->id,
            'section_name'  => 'Vocabulary',
            'question_type' => 'multi',
            'question_text' => 'Select all synonyms of the word "happy":',
            'difficulty'    => 'easy',
            'score'         => 3,
            'options'       => [
                ['option_text' => 'Joyful',    'is_correct' => true,  'display_order' => 0],
                ['option_text' => 'Sad',       'is_correct' => false, 'display_order' => 1],
                ['option_text' => 'Cheerful',  'is_correct' => true,  'display_order' => 2],
                ['option_text' => 'Miserable', 'is_correct' => false, 'display_order' => 3],
            ],
        ];

        $response = $this->actingAs($this->questionManager, 'admin')
            ->postJson('/api/v1/admin/questions', $payload);

        $response->assertCreated()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.question_type', 'multi');

        $correctCount = QuestionOption::where('question_id', $response->json('data.id'))
            ->where('is_correct', true)
            ->count();
        $this->assertEquals(2, $correctCount);
    }

    #[Test]
    public function can_create_true_false_question_with_exactly_two_options(): void
    {
        $payload = [
            'subject_id'    => $this->subject->id,
            'section_name'  => 'Grammar',
            'question_type' => 'true_false',
            'question_text' => 'The Earth revolves around the Sun.',
            'difficulty'    => 'easy',
            'score'         => 1,
            'options'       => [
                ['option_text' => 'صحیح (True)', 'is_correct' => true,  'display_order' => 0],
                ['option_text' => 'غلط (False)',  'is_correct' => false, 'display_order' => 1],
            ],
        ];

        $response = $this->actingAs($this->questionManager, 'admin')
            ->postJson('/api/v1/admin/questions', $payload);

        $response->assertCreated()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.question_type', 'true_false');

        $this->assertDatabaseHas('questions', [
            'id'            => $response->json('data.id'),
            'question_type' => 'true_false',
        ]);
        $this->assertDatabaseCount('question_options', 2);
    }

    #[Test]
    public function reject_true_false_question_with_invalid_options_count(): void
    {
        $payload = [
            'subject_id'    => $this->subject->id,
            'section_name'  => 'Grammar',
            'question_type' => 'true_false',
            'question_text' => 'Some statement.',
            'difficulty'    => 'easy',
            'score'         => 1,
            'options'       => [
                ['option_text' => 'Option 1', 'is_correct' => true,  'display_order' => 0],
                ['option_text' => 'Option 2', 'is_correct' => false, 'display_order' => 1],
                ['option_text' => 'Option 3', 'is_correct' => false, 'display_order' => 2],
            ],
        ];

        $response = $this->actingAs($this->questionManager, 'admin')
            ->postJson('/api/v1/admin/questions', $payload);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['options']);
    }

    #[Test]
    public function can_create_short_answer_question_without_options(): void
    {
        $payload = [
            'subject_id'    => $this->subject->id,
            'section_name'  => 'Vocabulary',
            'question_type' => 'short_answer',
            'question_text' => 'Write the noun form of the verb "decide".',
            'difficulty'    => 'medium',
            'score'         => 2,
            'options'       => [],
        ];

        $response = $this->actingAs($this->questionManager, 'admin')
            ->postJson('/api/v1/admin/questions', $payload);

        $response->assertCreated()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.question_type', 'short_answer');

        $this->assertDatabaseHas('questions', [
            'id'            => $response->json('data.id'),
            'question_type' => 'short_answer',
        ]);
    }

    #[Test]
    public function can_create_short_answer_question_with_answer_key(): void
    {
        $payload = [
            'subject_id'    => $this->subject->id,
            'section_name'  => 'Vocabulary',
            'question_type' => 'short_answer',
            'question_text' => 'Write the capital of Afghanistan.',
            'difficulty'    => 'easy',
            'score'         => 1.5,
            'options'       => [
                ['option_text' => 'Kabul', 'is_correct' => true, 'display_order' => 0],
            ],
        ];

        $response = $this->actingAs($this->questionManager, 'admin')
            ->postJson('/api/v1/admin/questions', $payload);

        $response->assertCreated()
            ->assertJsonPath('success', true);

        $this->assertDatabaseHas('question_options', [
            'question_id' => $response->json('data.id'),
            'option_text' => 'Kabul',
            'is_correct'  => true,
        ]);
    }

    #[Test]
    public function can_create_reading_question_with_passage_snapshot(): void
    {
        $passage = Passage::create([
            'subject_id' => $this->subject->id,
            'title'      => 'Kunduz Agriculture',
            'body_text'  => 'Kunduz is known as one of the most fertile agricultural provinces in the country.',
            'created_by' => $this->questionManager->id,
        ]);

        $payload = [
            'subject_id'    => $this->subject->id,
            'section_name'  => 'Reading',
            'question_type' => 'single',
            'question_text' => 'What is Kunduz province known for?',
            'difficulty'    => 'medium',
            'score'         => 2,
            'passage_id'    => $passage->id,
            'options'       => [
                ['option_text' => 'Fertile agriculture', 'is_correct' => true,  'display_order' => 0],
                ['option_text' => 'Heavy industry',     'is_correct' => false, 'display_order' => 1],
            ],
        ];

        $response = $this->actingAs($this->questionManager, 'admin')
            ->postJson('/api/v1/admin/questions', $payload);

        $response->assertCreated()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.passage_id', $passage->id);

        $question = Question::find($response->json('data.id'));
        $version = QuestionVersion::find($question->current_version_id);
        $this->assertNotNull($version);
        $this->assertEquals($passage->body_text, $version->passage_snapshot);
    }

    #[Test]
    public function can_create_listening_question_with_media(): void
    {
        $media = Media::create([
            'type'             => 'audio',
            'file_path'        => 'media/audio/test.mp3',
            'duration_seconds' => 120,
            'uploaded_by'      => $this->questionManager->id,
        ]);

        $payload = [
            'subject_id'    => $this->subject->id,
            'section_name'  => 'Listening',
            'question_type' => 'single',
            'question_text' => 'According to the speaker, when does the train leave?',
            'difficulty'    => 'medium',
            'score'         => 2,
            'media_id'      => $media->id,
            'options'       => [
                ['option_text' => 'At 9:30 AM', 'is_correct' => true,  'display_order' => 0],
                ['option_text' => 'At 10:00 AM','is_correct' => false, 'display_order' => 1],
            ],
        ];

        $response = $this->actingAs($this->questionManager, 'admin')
            ->postJson('/api/v1/admin/questions', $payload);

        $response->assertCreated()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.media_id', $media->id);
    }

    #[Test]
    public function single_choice_must_have_exactly_one_correct_option(): void
    {
        // Two correct options for single choice -> must fail
        $payload = [
            'subject_id'    => $this->subject->id,
            'section_name'  => 'Grammar',
            'question_type' => 'single',
            'question_text' => 'Choose one.',
            'difficulty'    => 'medium',
            'score'         => 2,
            'options'       => [
                ['option_text' => 'Option 1', 'is_correct' => true, 'display_order' => 0],
                ['option_text' => 'Option 2', 'is_correct' => true, 'display_order' => 1],
            ],
        ];

        $response = $this->actingAs($this->questionManager, 'admin')
            ->postJson('/api/v1/admin/questions', $payload);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['options']);
    }

    #[Test]
    public function lab_manager_cannot_create_questions(): void
    {
        $payload = [
            'subject_id'    => $this->subject->id,
            'section_name'  => 'Grammar',
            'question_type' => 'single',
            'question_text' => 'Unauthorized question.',
            'difficulty'    => 'easy',
            'score'         => 1,
            'options'       => [
                ['option_text' => 'A', 'is_correct' => true,  'display_order' => 0],
                ['option_text' => 'B', 'is_correct' => false, 'display_order' => 1],
            ],
        ];

        $response = $this->actingAs($this->labManager, 'admin')
            ->postJson('/api/v1/admin/questions', $payload);

        $response->assertForbidden();
    }
}
