<?php

namespace Tests\Unit;

use App\Enums\AccessLevel;
use App\Enums\MarcRelationship;
use App\Enums\MediaType;
use App\Koha\Marc\DataField;
use App\Koha\Marc\Managed856;
use App\Koha\Marc\MarcRecord;
use App\Models\DigitalAsset;
use InvalidArgumentException;
use Tests\Support\FakeKoha;
use Tests\TestCase;

class MarcRecordTest extends TestCase
{
    private function asset(array $attributes = []): DigitalAsset
    {
        $asset = new DigitalAsset(array_merge([
            'media_type' => MediaType::Document,
            'access_level' => AccessLevel::Public,
            'marc_relationship' => MarcRelationship::Resource,
        ], $attributes));
        $asset->uuid = $attributes['uuid'] ?? '01a1674d-2486-70a2-a041-9fff0cf8adb5';

        return $asset;
    }

    public function test_it_adds_a_managed_856_with_correct_indicators_and_subfields(): void
    {
        $record = MarcRecord::fromXml(FakeKoha::sampleRecord(42));
        $asset = $this->asset();

        $this->assertTrue((new Managed856)->apply($record, $asset));

        $managed = array_values(array_filter($record->dataFields('856'), fn (DataField $f) => (new Managed856)->isManaged($f)));
        $this->assertCount(1, $managed);
        $this->assertSame('4', $managed[0]->ind1);
        $this->assertSame('0', $managed[0]->ind2);
        $this->assertSame('https://dam.test/view/'.$asset->uuid, $managed[0]->first('u'));
        $this->assertSame('View digital resource', $managed[0]->first('y'));
        $this->assertSame('Full text', $managed[0]->first('3'));
        $this->assertSame('0', $managed[0]->first('7'));
    }

    public function test_indicators_and_access_follow_relationship_and_access_level(): void
    {
        $field = (new Managed856)->build($this->asset([
            'marc_relationship' => MarcRelationship::Related,
            'access_level' => AccessLevel::Restricted,
            'media_type' => MediaType::Video,
        ]));

        $this->assertSame('2', $field->ind2);
        $this->assertSame('1', $field->first('7'));
        $this->assertSame('Video recording', $field->first('3'));
        $this->assertNotNull($field->first('z'));
    }

    public function test_existing_fields_and_unrelated_856_are_preserved(): void
    {
        $record = MarcRecord::fromXml(FakeKoha::sampleRecord(42));
        $before = $record->fingerprints();

        (new Managed856)->apply($record, $this->asset());
        $after = MarcRecord::fromXml($record->toXml())->fingerprints();

        foreach ($before as $print) {
            $this->assertContains($print, $after);
        }

        $this->assertCount(count($before) + 1, $after);
        $this->assertStringContainsString('https://publisher.example/book', $record->toXml());
    }

    public function test_apply_is_idempotent_and_does_not_duplicate(): void
    {
        $record = MarcRecord::fromXml(FakeKoha::sampleRecord(42));
        $managed = new Managed856;
        $asset = $this->asset();

        $managed->apply($record, $asset);
        $this->assertFalse($managed->apply($record, $asset), 'Second apply must be a no-op');
        $this->assertCount(2, $record->dataFields('856'));
    }

    public function test_apply_replaces_an_outdated_managed_field(): void
    {
        $record = MarcRecord::fromXml(FakeKoha::sampleRecord(42));
        $managed = new Managed856;
        $asset = $this->asset();
        $managed->apply($record, $asset);

        $asset->link_text = 'Read the full text';
        $this->assertTrue($managed->apply($record, $asset));
        $this->assertTrue($managed->verifyPresent($record, $asset));
        $this->assertCount(2, $record->dataFields('856'));
    }

    public function test_multiple_assets_get_independent_fields_and_removal_is_targeted(): void
    {
        $record = MarcRecord::fromXml(FakeKoha::sampleRecord(42));
        $managed = new Managed856;
        $a = $this->asset();
        $b = $this->asset(['uuid' => '01a1674d-9021-70fc-9e0b-12c49b140fd8', 'media_type' => MediaType::Video]);

        $managed->apply($record, $a);
        $managed->apply($record, $b);
        $this->assertCount(3, $record->dataFields('856'));

        $this->assertTrue($managed->remove($record, $a));
        $this->assertTrue($managed->verifyAbsent($record, $a));
        $this->assertTrue($managed->verifyPresent($record, $b));
        $this->assertStringContainsString('https://publisher.example/book', $record->toXml());
    }

    public function test_fields_pointing_elsewhere_are_never_treated_as_managed(): void
    {
        $managed = new Managed856;

        $this->assertFalse($managed->isManaged(new DataField('856', '4', '0', [['u', 'https://dam.test/viewer/abc']])));
        $this->assertFalse($managed->isManaged(new DataField('856', '4', '0', [['u', 'https://other.example/view/01a1674d-2486-70a2-a041-9fff0cf8adb5']])));
        $this->assertTrue($managed->isManaged(new DataField('856', '4', '0', [['u', 'https://dam.test/view/01a1674d-2486-70a2-a041-9fff0cf8adb5']])));
    }

    public function test_new_field_is_inserted_in_tag_order(): void
    {
        $record = MarcRecord::fromXml(FakeKoha::sampleRecord(42, ''));
        (new Managed856)->apply($record, $this->asset());

        preg_match_all('/tag="(\d{3})"/', $record->toXml(), $m);
        $tags = $m[1];
        $sorted = $tags;
        sort($sorted);
        $this->assertSame($sorted, $tags);
    }

    public function test_it_rejects_doctype_and_invalid_xml(): void
    {
        $this->expectException(InvalidArgumentException::class);
        MarcRecord::fromXml('<?xml version="1.0"?><!DOCTYPE r [<!ENTITY x SYSTEM "file:///etc/passwd">]><record xmlns="http://www.loc.gov/MARC21/slim">&x;</record>');
    }

    public function test_it_rejects_garbage(): void
    {
        $this->expectException(InvalidArgumentException::class);
        MarcRecord::fromXml('not xml');
    }
}
