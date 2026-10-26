#!/usr/bin/perl
# Every boot: make sure the plugin is configured for the demo MAM and that the credentials the MAM
# needs (the Koha API key and the sign-in secret) exist and match. Writes them to $MAM_SHARED_ENV for
# the MAM container. Run through koha-shell after the plugin is installed.
use Modern::Perl;
use C4::Context;
use Koha::Plugins;    # puts the plugin directory on @INC
use Koha::ApiKeys;
use Koha::Patrons;
use Koha::Plugin::Com::Library::MediaManager;
use Koha::Plugin::Com::Library::MediaManager::Security qw(secret_hash random_hex);

my $file = $ENV{MAM_SHARED_ENV} or die "MAM_SHARED_ENV is required\n";
my %current;
if (open my $fh, '<', $file) {
    while (<$fh>) { $current{$1} = $2 if /^(\w+)=(.*)$/; }
}

my $plugin = Koha::Plugin::Com::Library::MediaManager->new;
my $api_user = Koha::Patrons->find({ userid => 'mam-api' }) or die "Patron mam-api is missing\n";

# Sign-in secret: keep the shared one while the plugin still holds its hash.
my $secret = $current{KOHA_SSO_CLIENT_SECRET} // '';
unless ($secret =~ /\A[0-9a-f]{64}\z/ && secret_hash($secret) eq ($plugin->retrieve_data('client_secret_hash') // '')) {
    $secret = random_hex();
    say 'New sign-in secret generated.';
}

# API key: keep the shared one while it is still active for mam-api.
my ($client_id, $client_secret) = @current{qw(KOHA_API_CLIENT_ID KOHA_API_CLIENT_SECRET)};
my $key = $client_id && Koha::ApiKeys->search({ client_id => $client_id, patron_id => $api_user->borrowernumber, active => 1 })->next;
unless ($key && $key->validate_secret($client_secret // '')) {
    $_->delete for Koha::ApiKeys->search({ patron_id => $api_user->borrowernumber })->as_list;
    $key = Koha::ApiKey->new({ patron_id => $api_user->borrowernumber, description => 'MAM demo' })->store;
    ($client_id, $client_secret) = ($key->client_id, $key->plain_text_secret);
    say 'New API key generated for mam-api.';
}

$plugin->store_data({
    mam_url            => $ENV{MAM_URL},
    instance_id        => $ENV{KOHA_INSTANCE_ID},
    client_id          => $ENV{KOHA_SSO_CLIENT_ID},
    client_secret_hash => secret_hash($secret),
    protocol           => 'code-v2',
    insecure_http      => '1',                         # demo runs on plain HTTP
    health_ips         => $ENV{MAM_HEALTH_IP} // '',   # lets "Test connection" reach the MAM container
});

my $tmp = "$file.tmp";
open my $out, '>', $tmp or die "Cannot write $tmp: $!\n";
print {$out} "KOHA_API_CLIENT_ID=$client_id\nKOHA_API_CLIENT_SECRET=$client_secret\nKOHA_SSO_CLIENT_SECRET=$secret\n";
close $out;
chmod 0644, $tmp;
rename $tmp, $file or die "Cannot write $file: $!\n";
say 'MAM credentials ready.';
