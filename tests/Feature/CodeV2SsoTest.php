<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * code-v2 hand-off with Koha Media Manager plugin 2.x, against an HTTP fake
 * of the plugin's exchange endpoint (contract: plugin docs/SSO_PROTOCOL.md).
 */
class CodeV2SsoTest extends TestCase
{
    use RefreshDatabase;

    private const INITIATION = 'b2b2b2b2b2b2b2b2b2b2b2b2b2b2b2b2b2b2b2b2b2b2b2b2b2b2b2b2b2b2b2b2';

    private const CODE = 'c3c3c3c3c3c3c3c3c3c3c3c3c3c3c3c3c3c3c3c3c3c3c3c3c3c3c3c3c3c3c3c3';

    /** @var list<Request> */
    private array $exchanges = [];

    /** Run /sso/koha/start and return the state the MAM bound to this browser. */
    private function start(): string
    {
        $response = $this->get('/sso/koha/start?request='.self::INITIATION);
        $response->assertStatus(303);

        $location = $response->headers->get('Location');
        $this->assertStringStartsWith('https://koha.test/cgi-bin/koha/plugins/run.pl?', $location);
        parse_str(parse_url($location, PHP_URL_QUERY), $query);

        $this->assertSame('Koha::Plugin::Com::Library::MediaManager', $query['class']);
        $this->assertSame('tool', $query['method']);
        $this->assertSame('continue', $query['op']);
        $this->assertSame(self::INITIATION, $query['request']);
        $this->assertMatchesRegularExpression('/^[A-Za-z0-9_-]{43,128}$/', $query['state']);

        return $query['state'];
    }

    /** @var array{overrides: array, status: int, mutate: ?callable} */
    private array $fake = ['overrides' => [], 'status' => 200, 'mutate' => null];

    private bool $fakeInstalled = false;

    /**
     * Configure the exchange response. Http::fake callbacks stack, so one
     * callback is registered and reads the current configuration.
     */
    private function fakeExchange(array $overrides = [], int $status = 200, ?callable $mutate = null): void
    {
        $this->fake = compact('overrides', 'status', 'mutate');

        if ($this->fakeInstalled) {
            return;
        }

        $this->fakeInstalled = true;

        Http::fake(function (Request $request) {
            $this->exchanges[] = $request;
            ['overrides' => $overrides, 'status' => $status, 'mutate' => $mutate] = $this->fake;

            if ($status !== 200) {
                return Http::response($overrides ?: ['error' => 'invalid_grant'], $status);
            }

            $body = [
                'success' => true,
                'protocol' => 'code-v2',
                'iss' => 'koha-test',
                'aud' => 'media-manager',
                'identity' => ['provider' => 'koha', 'instance' => 'koha-test', 'patron_id' => 1001, 'userid' => 'cataloguer01'],
                'context' => ['biblio_id' => 12345, 'branch' => 'MAIN'],
                'capabilities' => ['media.access', 'media.upload'],
                'issued_at' => now()->getTimestamp(),
                'expires_at' => now()->getTimestamp() + 60,
                'correlation_id' => str_repeat('d', 64),
                'state' => $this->pendingState,
            ];

            // Nested objects merge; lists (capabilities) are replaced as a whole.
            foreach ($overrides as $key => $value) {
                $body[$key] = is_array($value) && is_array($body[$key] ?? null) && ! array_is_list($value)
                    ? array_replace($body[$key], $value)
                    : $value;
            }

            return Http::response($mutate ? $mutate($body) : $body);
        });
    }

    private ?string $pendingState = null;

    private function ssoCallback(?string $state = null, string $code = self::CODE)
    {
        return $this->get('/sso/koha?code='.$code.'&state='.($state ?? $this->pendingState));
    }

    public function test_full_handoff_signs_in_and_lands_on_the_record(): void
    {
        $this->pendingState = $this->start();
        $this->fakeExchange();

        $this->ssoCallback()->assertStatus(303)->assertRedirect(route('biblios.show', 12345))->assertHeader('Referrer-Policy', 'no-referrer');

        $this->assertAuthenticated();
        $user = User::first();
        $this->assertSame(1001, $user->koha_patron_id);
        $this->assertSame('cataloguer01', $user->koha_userid);
        $this->assertSame('koha-test', $user->koha_instance_id);
        $this->assertSame('MAIN', $user->branch_code);
        $this->assertSame(['media.access', 'media.upload'], session('dam.koha_capabilities'));

        // Server-to-server request exactly as the plugin contract requires.
        $request = $this->exchanges[0];
        $this->assertSame('POST', $request->method());
        $this->assertSame('https://koha.test/api/v1/contrib/media-manager/exchange', $request->url());
        $this->assertSame(['code' => self::CODE], $request->data());
        $this->assertSame(config('dam.sso.client_id'), $request->header('X-Media-Client')[0]);
        $this->assertSame(str_repeat('a', 64), $request->header('X-Media-Secret')[0]);
        $this->assertFalse($request->hasHeader('Authorization'));
        $this->assertFalse($request->hasHeader('Cookie'));
        $this->assertStringStartsWith('application/json', $request->header('Content-Type')[0]);
    }

    public function test_well_known_endpoint_advertises_protocol(): void
    {
        $this->getJson('/.well-known/koha-media-manager')->assertExactJson(['protocol' => 'code-v2', 'audience' => 'media-manager']);
    }

    public function test_unsolicited_callback_is_rejected_before_any_exchange(): void
    {
        $this->fakeExchange();

        $this->ssoCallback(str_repeat('x', 43))->assertForbidden();

        $this->assertCount(0, $this->exchanges, 'Koha must not be contacted for an unknown state');
        $this->assertGuest();
    }

    public function test_state_is_single_use(): void
    {
        $this->pendingState = $this->start();
        $this->fakeExchange();
        $this->ssoCallback()->assertRedirect();
        auth()->logout();

        $this->ssoCallback()->assertForbidden();
        $this->assertCount(1, $this->exchanges);
    }

    public function test_expired_state_is_rejected(): void
    {
        $this->pendingState = $this->start();
        $this->fakeExchange();
        $this->travel(config('dam.sso.state_ttl') + 5)->seconds();

        $this->ssoCallback()->assertForbidden();
        $this->assertCount(0, $this->exchanges);
    }

    public function test_code_substitution_with_another_state_is_rejected(): void
    {
        $this->pendingState = $this->start();
        // Koha's grant belongs to a different browser state (another user's code).
        $this->fakeExchange(['state' => str_repeat('Z', 43)]);

        $this->ssoCallback()->assertForbidden();
        $this->assertGuest();
    }

    public function test_wrong_issuer_audience_or_instance_is_rejected(): void
    {
        foreach ([['iss' => 'other-koha'], ['aud' => 'other-app'], ['identity' => ['instance' => 'other-koha']], ['protocol' => 'code-v1'], ['success' => false]] as $override) {
            $this->exchanges = [];
            $this->pendingState = $this->start();
            $this->fakeExchange($override);

            $this->ssoCallback()->assertStatus(503);
            $this->assertGuest();
        }
    }

    public function test_item_level_handoff_lands_on_the_item_workspace(): void
    {
        $this->pendingState = $this->start();
        $this->fakeExchange(['context' => ['item_id' => 678]]);

        $this->ssoCallback()->assertStatus(303)->assertRedirect(route('biblios.show', ['biblio' => 12345, 'item' => 678]));
        $this->assertAuthenticated();
    }

    public function test_null_or_absent_item_is_a_record_level_handoff(): void
    {
        $this->pendingState = $this->start();
        $this->fakeExchange(['context' => ['item_id' => null]]);

        $this->ssoCallback()->assertStatus(303)->assertRedirect(route('biblios.show', 12345));
    }

    public function test_malformed_identity_or_context_is_rejected(): void
    {
        foreach ([
            ['identity' => ['patron_id' => '1001']],
            ['identity' => ['patron_id' => 0]],
            ['context' => ['biblio_id' => -1]],
            ['context' => ['item_id' => 0]],
            ['context' => ['item_id' => '678']],
            ['capabilities' => ['media.upload']],
            ['capabilities' => ['media.access', 'media.superpower']],
            ['correlation_id' => 'nothex'],
        ] as $override) {
            $this->pendingState = $this->start();
            $this->fakeExchange($override);

            $this->ssoCallback()->assertStatus(503);
            $this->assertGuest();
        }
    }

    public function test_koha_errors_are_mapped_and_never_sign_in(): void
    {
        foreach ([[400, ['error' => 'invalid_grant'], 403], [401, ['error' => 'invalid_client'], 503], [403, ['error' => 'access_denied'], 403], [429, ['error' => 'rate_limited'], 429], [503, ['error' => 'temporarily_unavailable'], 503], [404, [], 503]] as [$kohaStatus, $body, $expected]) {
            $this->pendingState = $this->start();
            $this->fakeExchange($body, $kohaStatus);

            $this->ssoCallback()->assertStatus($expected);
            $this->assertGuest();
        }
    }

    public function test_malformed_parameters_are_rejected(): void
    {
        $this->get('/sso/koha/start?request=not-hex')->assertForbidden();
        $this->get('/sso/koha?code=short&state='.str_repeat('a', 43))->assertForbidden();
        $this->get('/sso/koha?code='.self::CODE.'&state=bad')->assertForbidden();
    }

    public function test_misconfiguration_fails_closed(): void
    {
        config(['dam.sso.client_secret' => 'too-short']);
        $this->get('/sso/koha/start?request='.self::INITIATION)->assertStatus(503);

        config(['dam.sso.client_secret' => str_repeat('a', 64), 'dam.sso.exchange_url' => 'http://koha.test/api/v1/contrib/media-manager/exchange']);
        $this->get('/sso/koha/start?request='.self::INITIATION)->assertStatus(503);
    }

    public function test_insecure_http_mode_allows_http_koha_urls(): void
    {
        config([
            'dam.sso.allow_insecure_http' => true,
            'dam.sso.return_url' => 'http://10.1.10.50:7002/cgi-bin/koha/plugins/run.pl',
            'dam.sso.exchange_url' => 'http://10.1.10.50:7002/api/v1/contrib/media-manager/exchange',
        ]);

        $response = $this->get('/sso/koha/start?request='.self::INITIATION)->assertStatus(303);
        $this->assertStringStartsWith('http://10.1.10.50:7002/cgi-bin/koha/plugins/run.pl?class=Koha%3A%3APlugin', $response->headers->get('Location'));
    }

    public function test_koha_capabilities_cap_the_dam_role(): void
    {
        User::factory()->create(['koha_instance_id' => 'koha-test', 'koha_patron_id' => 1001, 'koha_userid' => 'cataloguer01', 'role' => 'administrator']);
        $this->pendingState = $this->start();
        $this->fakeExchange();
        $this->ssoCallback()->assertRedirect();

        // MAM administrator, but Koha only grants media.access + media.upload.
        $this->get('/staff')->assertOk();
        $this->get('/staff/admin/settings')->assertForbidden();
        $this->get('/staff/approvals')->assertForbidden();

        $user = auth()->user();
        $this->assertTrue($user->hasCapability('media.upload'));
        $this->assertFalse($user->hasCapability('media.publish'));
    }

    public function test_superlibrarian_capabilities_unlock_the_dam_role(): void
    {
        User::factory()->create(['koha_instance_id' => 'koha-test', 'koha_patron_id' => 1001, 'koha_userid' => 'cataloguer01', 'role' => 'administrator']);
        $this->pendingState = $this->start();
        $this->fakeExchange(mutate: fn ($b) => ['capabilities' => ['media.access', 'media.upload', 'media.manage', 'media.admin']] + $b);
        $this->ssoCallback()->assertRedirect();

        $this->get('/staff/admin/settings')->assertOk();
    }

    public function test_session_without_koha_capabilities_is_not_trusted(): void
    {
        $user = User::factory()->role('administrator')->create();

        $this->actingAs($user)->withSession(['dam.authenticated_at' => now()->getTimestamp()])
            ->get('/staff')->assertRedirect(route('login'));
    }

    public function test_legacy_hmac_callback_is_not_accepted(): void
    {
        $ts = time();
        $sig = hash_hmac('sha256', "jdoe|1|42|{$ts}", str_repeat('0', 64));

        $this->get("/sso/koha?userid=jdoe&borrowernumber=1&biblio_id=42&timestamp={$ts}&signature={$sig}")->assertForbidden();
        $this->assertGuest();
    }

    public function test_session_is_regenerated_on_login(): void
    {
        $this->pendingState = $this->start();
        $before = session()->getId();
        $this->fakeExchange();

        $this->ssoCallback()->assertRedirect();

        $this->assertNotSame($before, session()->getId());
    }

    public function test_suspended_identity_is_refused(): void
    {
        User::factory()->create(['koha_instance_id' => 'koha-test', 'koha_patron_id' => 1001, 'koha_userid' => 'cataloguer01', 'role' => 'suspended', 'is_active' => false]);
        $this->pendingState = $this->start();
        $this->fakeExchange();

        $this->ssoCallback()->assertForbidden();
        $this->assertGuest();
    }

    public function test_bootstrap_admin_receives_administrator_role(): void
    {
        $this->pendingState = $this->start();
        $this->fakeExchange(['identity' => ['patron_id' => 1, 'userid' => 'rootadmin']]);

        $this->ssoCallback()->assertRedirect();

        $this->assertSame('administrator', User::where('koha_userid', 'rootadmin')->value('role'));
    }

    public function test_existing_role_is_kept_on_subsequent_login(): void
    {
        User::factory()->create(['koha_instance_id' => 'koha-test', 'koha_patron_id' => 1001, 'koha_userid' => 'cataloguer01', 'role' => 'reviewer']);
        $this->pendingState = $this->start();
        $this->fakeExchange();

        $this->ssoCallback()->assertRedirect();

        $this->assertSame('reviewer', User::where('koha_patron_id', 1001)->value('role'));
        $this->assertSame(1, User::count());
        $this->assertDatabaseHas('activity_log', ['event' => 'sso_login']);
    }

    public function test_absolute_session_lifetime_is_enforced(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->withSession([
                'dam.authenticated_at' => now()->subMinutes(config('dam.sso.absolute_lifetime_minutes') + 1)->getTimestamp(),
                'dam.koha_capabilities' => ['media.access'],
            ])
            ->get('/staff')
            ->assertRedirect(route('login'));

        $this->assertGuest();
    }

    public function test_there_is_no_password_login(): void
    {
        $this->post('/login', ['userid' => 'x', 'password' => 'y'])->assertStatus(405);
        $this->get('/login')->assertOk()->assertSee('Sign in through Koha');
    }
}
