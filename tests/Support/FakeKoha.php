<?php

namespace Tests\Support;

use Closure;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

/**
 * In-memory stand-in for the Koha REST API (OAuth token, GET/PUT biblio).
 * Lets tests script failures and concurrent edits without a real Koha.
 */
class FakeKoha
{
    /** @var array<int, string> MARCXML by biblio id */
    public array $records = [];

    public int $puts = 0;

    /** @var list<array{biblio: int, body: string, headers: array}> */
    public array $putRequests = [];

    /** @var array<string, list<Closure|int>> queued overrides keyed by "METHOD id" */
    private array $overrides = [];

    /** Called after each MARC GET; may mutate the record to simulate concurrent edits. */
    public ?Closure $afterMarcRead = null;

    public bool $dropManagedFieldOnPut = false;

    /** @var array<int, array> Koha item JSON by itemnumber */
    public array $items = [];

    public int $itemReads = 0;

    /** @var list<array{item: int, body: array}> */
    public array $itemPuts = [];

    public static function install(): self
    {
        $fake = new self;

        Http::fake(function (Request $request) use ($fake) {
            return $fake->handle($request);
        });

        return $fake;
    }

    public function withRecord(int $id, ?string $xml = null): self
    {
        $this->records[$id] = $xml ?? self::sampleRecord($id);

        return $this;
    }

    /** An item of a record; a null barcode is an item saved before one was assigned. */
    public function withItem(int $id, int $biblioId, ?string $barcode = null, array $extra = []): self
    {
        $this->items[$id] = $extra + ['item_id' => $id, 'biblio_id' => $biblioId, 'external_id' => $barcode, 'home_library_id' => 'MAIN', 'callnumber' => null, 'copy_number' => null, 'uri' => null];

        return $this;
    }

    /** Queue a status code (or closure) for the next matching request, e.g. fail('PUT', 42, 503). */
    public function fail(string $method, int $id, int $status, int $times = 1): self
    {
        for ($i = 0; $i < $times; $i++) {
            $this->overrides["{$method} {$id}"][] = $status;
        }

        return $this;
    }

    public function handle(Request $request)
    {
        $path = parse_url($request->url(), PHP_URL_PATH);

        if ($path === '/api/v1/oauth/token') {
            return Http::response(['access_token' => 'test-token', 'expires_in' => 3600]);
        }

        if (preg_match('#^/api/v1/biblios/(\d+)/items/(\d+)$#', $path, $m) && $request->method() === 'PUT') {
            $item = $this->items[(int) $m[2]] ?? null;

            if (! $item || $item['biblio_id'] !== (int) $m[1]) {
                return Http::response(['error' => 'Item not found'], 404);
            }

            if (! empty($this->overrides["PUT item {$m[2]}"])) {
                $status = array_shift($this->overrides["PUT item {$m[2]}"]);

                return Http::response(['error' => "Simulated {$status}"], $status);
            }

            $this->itemPuts[] = ['item' => (int) $m[2], 'body' => $request->data()];
            $this->items[(int) $m[2]] = array_replace($item, $request->data());

            return Http::response($this->items[(int) $m[2]]);
        }

        if (preg_match('#^/api/v1/items/(\d+)$#', $path, $m)) {
            $this->itemReads++;

            if (! empty($this->overrides["GET item {$m[1]}"])) {
                $status = array_shift($this->overrides["GET item {$m[1]}"]);

                return Http::response(['error' => "Simulated {$status}"], $status);
            }

            return isset($this->items[(int) $m[1]])
                ? Http::response($this->items[(int) $m[1]])
                : Http::response(['error' => 'Item not found.'], 404);
        }

        if (! preg_match('#^/api/v1/biblios/(\d+)$#', $path, $m)) {
            return Http::response(['error' => 'not found'], 404);
        }

        $id = (int) $m[1];
        $method = $request->method();

        if (! empty($this->overrides["{$method} {$id}"])) {
            $status = array_shift($this->overrides["{$method} {$id}"]);

            return Http::response(['error' => "Simulated {$status}"], $status);
        }

        if (! isset($this->records[$id])) {
            return Http::response(['error' => 'Object not found.'], 404);
        }

        if ($method === 'PUT') {
            $this->puts++;
            $this->putRequests[] = ['biblio' => $id, 'body' => $request->body(), 'headers' => $request->headers()];
            $body = preg_replace('#<controlfield tag="005">[^<]*</controlfield>#', '<controlfield tag="005">'.date('YmdHis').'.'.$this->puts.'</controlfield>', $request->body());

            if ($this->dropManagedFieldOnPut) {
                $body = preg_replace('#<datafield tag="856"[^>]*>(?:(?!</datafield>).)*dam\.test/view(?:(?!</datafield>).)*</datafield>#s', '', $body);
            }

            $this->records[$id] = $body;

            return Http::response(['id' => $id]);
        }

        if (str_contains($request->header('Accept')[0] ?? '', 'marcxml')) {
            $xml = $this->records[$id];

            if ($this->afterMarcRead) {
                ($this->afterMarcRead)($this, $id);
            }

            return Http::response($xml, 200, ['Content-Type' => 'application/marcxml+xml']);
        }

        return Http::response(['biblio_id' => $id, 'title' => 'Sample', 'framework_id' => 'BKS']);
    }

    public static function sampleRecord(int $id, string $extra856 = '<datafield tag="856" ind1="4" ind2="1"><subfield code="u">https://publisher.example/book</subfield><subfield code="y">Publisher</subfield></datafield>'): string
    {
        return <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<record xmlns="http://www.loc.gov/MARC21/slim">
  <leader>00000nam a22000007a 4500</leader>
  <controlfield tag="001">{$id}</controlfield>
  <controlfield tag="005">20260101120000.0</controlfield>
  <datafield tag="020" ind1=" " ind2=" "><subfield code="a">9780199608249 (hbk.)</subfield></datafield>
  <datafield tag="100" ind1="1" ind2=" "><subfield code="a">Rizvi, Adib,</subfield></datafield>
  <datafield tag="245" ind1="1" ind2="0"><subfield code="a">Renal transplantation :</subfield><subfield code="b">principles /</subfield><subfield code="c">A. Rizvi.</subfield></datafield>
  <datafield tag="250" ind1=" " ind2=" "><subfield code="a">2nd ed.</subfield></datafield>
  <datafield tag="264" ind1=" " ind2="1"><subfield code="a">Karachi :</subfield><subfield code="b">SIUT,</subfield><subfield code="c">2024.</subfield></datafield>
  {$extra856}
  <datafield tag="942" ind1=" " ind2=" "><subfield code="c">BK</subfield></datafield>
  <datafield tag="999" ind1=" " ind2=" "><subfield code="c">{$id}</subfield><subfield code="d">{$id}</subfield></datafield>
</record>
XML;
    }
}
