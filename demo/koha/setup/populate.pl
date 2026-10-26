#!/usr/bin/perl
# First boot only: install the Koha database the way the web installer does, then add the demo
# librarian, the MAM API user and a few sample records. Run through koha-shell.
use Modern::Perl;
use C4::Context;
use C4::Installer;
use C4::Biblio qw(AddBiblio);
use Koha::Database;
use Koha::Patrons;
use Koha::Item;
use MARC::Record;
use MARC::Field;

my ($librarian_userid, $librarian_password) = @ENV{qw(KOHA_DEMO_USER KOHA_DEMO_PASSWORD)};
die "KOHA_DEMO_USER and KOHA_DEMO_PASSWORD are required\n" unless $librarian_userid && $librarian_password;

# --- Database: schema, mandatory data, all English sample data -----------------------------------
my $installer = C4::Installer->new;
if (my $error = $installer->load_db_schema) { die "Schema load failed: $error\n"; }
$installer->set_marcflavour_syspref('MARC21');
my (undef, $frameworks) = $installer->marc_framework_sql_list('en', 'marc21');
my (undef, $samples) = $installer->sample_data_sql_list('en');
my @files = map { $_->{fwkfile} } map { @{ $_->{frameworks} } } @$frameworks, @$samples;
my (undef, $loaded) = $installer->load_sql_in_order('en', [], @files);
for my $level (@$loaded) {
    warn "  $level->{level}/$_->{fwkname}: " . join(' ', @{ $_->{error} }) . "\n"
        for grep { $_->{error} && @{ $_->{error} } } @{ $level->{fwklist} };
}
$installer->set_version_syspref;
$installer->set_languages_syspref('en');
say 'Koha database installed.';

# --- System preferences ---------------------------------------------------------------------------
my %prefs = (
    RESTOAuth2ClientCredentials => 1,       # the MAM calls Koha's REST API with an API key
    StaffClientBaseURL          => $ENV{KOHA_STAFF_URL},
    OPACBaseURL                 => $ENV{KOHA_OPAC_URL},
    LibraryName                 => 'MAM Demo Library',
    LocalCoverImages            => 1,       # also gives each item an Actions menu, where the plugin adds Attach Media
    OPACLocalCoverImages        => 1,
);
C4::Context->set_preference($_, $prefs{$_}) for keys %prefs;

# --- Staff accounts -------------------------------------------------------------------------------
my $branch = 'CPL';    # "Centerville" from the sample libraries
my $librarian = Koha::Patron->new({
    userid => $librarian_userid, cardnumber => 'DEMO0001', surname => 'Librarian', firstname => 'Demo',
    branchcode => $branch, categorycode => 'S', flags => 1,    # superlibrarian
})->store;
$librarian->set_password({ password => $librarian_password, skip_validation => 1 });
say "Superlibrarian '$librarian_userid' created.";

# The MAM reads and updates records through the REST API as this user (see link_mam.pl for its key).
my $api_user = Koha::Patron->new({
    userid => 'mam-api', cardnumber => 'MAMAPI01', surname => 'MAM API', firstname => 'Service',
    branchcode => $branch, categorycode => 'S', flags => 2**2,    # catalogue
})->store;
my $dbh = C4::Context->dbh;
$dbh->do(q{INSERT INTO user_permissions (borrowernumber, module_bit, code) VALUES (?, 9, ?)},
    undef, $api_user->borrowernumber, $_) for qw(edit_catalogue edit_items);
say "API user 'mam-api' created.";

# --- Sample records -------------------------------------------------------------------------------
my @records = (
    ['Alice\'s Adventures in Wonderland', 'Carroll, Lewis', 'London', 'Macmillan', '1865', 2],
    ['Pride and Prejudice', 'Austen, Jane', 'London', 'T. Egerton', '1813', 2],
    ['On the Origin of Species', 'Darwin, Charles', 'London', 'John Murray', '1859', 1],
    ['The Adventures of Sherlock Holmes', 'Doyle, Arthur Conan', 'London', 'George Newnes', '1892', 1],
    ['Gray\'s Anatomy: Descriptive and Surgical', 'Gray, Henry', 'London', 'John W. Parker', '1858', 1],
    ['Oral history interviews: local library memories', 'MAM Demo Library', 'Centerville', 'MAM Demo Library', '2026', 0],
);
my $barcode = 39000000000001;
for my $r (@records) {
    my ($title, $author, $place, $publisher, $year, $copies) = @$r;
    my $marc = MARC::Record->new;
    $marc->leader('00000nam a22000007a 4500');
    $marc->append_fields(
        MARC::Field->new('100', '1', ' ', a => $author),
        MARC::Field->new('245', '1', '0', a => $title),
        MARC::Field->new('264', ' ', '1', a => $place, b => $publisher, c => $year),
        MARC::Field->new('520', ' ', ' ', a => 'Sample record for the Media Asset Management (MAM) demo.'),
        MARC::Field->new('942', ' ', ' ', c => 'BK'),
    );
    my ($biblionumber, $biblioitemnumber) = AddBiblio($marc, '');
    for my $copy (1 .. $copies) {
        Koha::Item->new({
            biblionumber => $biblionumber, biblioitemnumber => $biblioitemnumber,
            homebranch => $branch, holdingbranch => $branch, itype => 'BK',
            barcode => $barcode++, itemcallnumber => sprintf('DEMO %03d.%d', $biblionumber, $copy),
        })->store;
    }
}
say scalar(@records) . ' sample records added.';
