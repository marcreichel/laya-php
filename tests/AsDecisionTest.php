<?php

declare(strict_types=1);

use App\Decisions\Area;
use App\Decisions\Areas;
use App\Decisions\Code;
use App\Decisions\Coded;
use App\Decisions\Department;
use App\Decisions\Prospect;
use App\Decisions\Triage;
use App\Decisions\Urgency;
use App\Decisions\Urgent;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use MarcReichel\Laya\Attributes\Ask;
use MarcReichel\Laya\Exceptions\InvalidDecisionCastException;
use MarcReichel\Laya\Laravel\AsDecision;
use Orchestra\Testbench\TestCase;

require_once __DIR__.'/Fixtures/Decisions.php';

uses(TestCase::class);

final readonly class Hidden
{
    public function __construct(#[Ask('Which department?')] private Department $department) {}

    public function department(): Department
    {
        return $this->department;
    }
}

final class CastTicket extends Model
{
    protected $table = 'tickets';

    protected $guarded = [];

    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'triage' => AsDecision::of(Triage::class),
            'string_form' => AsDecision::class.':'.Triage::class,
            'areas' => AsDecision::of(Areas::class),
            'coded' => AsDecision::of(Coded::class),
            'urgent' => AsDecision::of(Urgent::class),
            'prospect' => AsDecision::of(Prospect::class),
            'hidden' => AsDecision::of(Hidden::class),
        ];
    }
}

beforeEach(function () {
    config(['database.default' => 'testing', 'database.connections.testing' => ['driver' => 'sqlite', 'database' => ':memory:']]);
    Schema::create('tickets', function ($table) {
        $table->id();
        foreach (['triage', 'string_form', 'areas', 'coded', 'urgent', 'prospect', 'hidden'] as $column) {
            $table->json($column)->nullable();
        }
    });
});

it('round-trips a decision through the database', function () {
    $ticket = CastTicket::create(['triage' => new Triage(Department::Billing, 2, true)]);

    expect(DB::table('tickets')->value('triage'))->toBe('{"department":"billing","urgency":2,"churn":true}');

    $triage = CastTicket::find($ticket->id)->triage;
    expect($triage)->toEqual(new Triage(Department::Billing, 2, true))
        ->and($triage->department)->toBe(Department::Billing);
});

it('accepts the string form', function () {
    $ticket = CastTicket::create(['string_form' => new Triage(Department::Other, 0, false)]);

    expect($ticket->fresh()->string_form)->toEqual(new Triage(Department::Other, 0, false));
});

it('maps null to null in both directions, and keeps null parameters', function () {
    $ticket = CastTicket::create(['triage' => null]);
    expect($ticket->fresh()->triage)->toBeNull();

    $ticket->triage = new Triage(Department::Technical, 1, null);
    $ticket->save();
    expect($ticket->fresh()->triage->churn)->toBeNull();
});

it('round-trips enum lists, int enums, scale enums and floats', function () {
    $ticket = CastTicket::create([
        'areas' => new Areas([Area::Docs, Area::Billing], true),
        'coded' => new Coded(Code::One),
        'urgent' => new Urgent(Urgency::High),
        'prospect' => new Prospect(1.5, 1),
    ]);

    expect(DB::table('tickets')->value('areas'))->toBe('{"areas":["docs.api","billing"],"churn":true}')
        ->and(DB::table('tickets')->value('coded'))->toBe('{"code":1}');

    $fresh = $ticket->fresh();
    expect($fresh->areas)->toEqual(new Areas([Area::Docs, Area::Billing], true))
        ->and($fresh->coded)->toEqual(new Coded(Code::One))
        ->and($fresh->urgent)->toEqual(new Urgent(Urgency::High))
        ->and($fresh->prospect->budget)->toBe(1.0);
});

it('round-trips a null list', function () {
    $ticket = CastTicket::create(['areas' => new Areas(null, false)]);

    expect($ticket->fresh()->areas->areas)->toBeNull();
});

it('rejects a value that is not the decision class', function (mixed $value, string $given) {
    expect(fn () => new CastTicket(['triage' => $value()]))
        ->toThrow(InvalidDecisionCastException::class, sprintf('CastTicket::$triage takes a %s, got %s.', Triage::class, $given));
})->with([
    'other object' => [fn () => new Urgent(Urgency::Low), Urgent::class],
    'array' => [fn () => ['department' => 'billing'], 'array'],
]);

it('names the attribute when the stored JSON does not fit', function (string $json, string $problem) {
    DB::table('tickets')->insert(['triage' => $json]);

    expect(fn () => CastTicket::first()->triage)
        ->toThrow(InvalidDecisionCastException::class, sprintf('CastTicket::$triage holds a value that doesn\'t fit %s: it %s.', Triage::class, $problem));
})->with([
    'missing key' => ['{"department":"billing","urgency":2}', 'has no value for $churn'],
    'unknown enum value' => ['{"department":"sales","urgency":2,"churn":true}', 'has "sales" for $department, which isn\'t a Department case'],
    'array enum value' => ['{"department":["billing"],"urgency":2,"churn":true}', 'has ["billing"] for $department, which isn\'t a Department case'],
    'wrong type' => ['{"department":"billing","urgency":"2","churn":true}', 'has string for $urgency, not int'],
    'int for bool' => ['{"department":"billing","urgency":2,"churn":1}', 'has int for $churn, not bool'],
    'null in non-null' => ['{"department":null,"urgency":2,"churn":true}', 'has null for $department, which isn\'t a Department case'],
    'not an object' => ['5', 'is not a JSON object'],
    'invalid json' => ['{', 'is not a JSON object'],
]);

it('rejects a list that is not a list', function () {
    DB::table('tickets')->insert(['areas' => '{"areas":"billing","churn":true}']);

    expect(fn () => CastTicket::first()->areas)->toThrow(InvalidDecisionCastException::class, 'it has no list for $areas');
});

it('rejects a JSON object where a list belongs', function () {
    DB::table('tickets')->insert(['areas' => '{"areas":{"extra":"docs.api"},"churn":true}']);

    expect(fn () => CastTicket::first()->areas)->toThrow(InvalidDecisionCastException::class, 'it has no list for $areas');
});

it('stores a decision with private promoted properties', function () {
    $ticket = CastTicket::create(['hidden' => new Hidden(Department::Other)]);

    expect($ticket->fresh()->hidden->department())->toBe(Department::Other);
});

it('needs a decision class', function () {
    expect(fn () => AsDecision::castUsing([]))->toThrow(InvalidArgumentException::class);
});

it('rejects a string for an int-backed enum', function () {
    DB::table('tickets')->insert(['coded' => '{"code":"1"}']);

    expect(fn () => CastTicket::first()->coded)->toThrow(InvalidDecisionCastException::class, 'it has "1" for $code, which isn\'t a Code case');
});
