// Edit this file to customize for your site.
//
// The following parameters define your connection to the CiviCRM database:
// Domain name of the host with the database.
define('CIVI_HOST',   'localhost');

// MySQL user (NOT the Linux user!) that can access the
// database.  This user MUST have the MySQL Alter Table privilege.
define('CIVI_USER',   'xxxxx');

// MySQL password for the above user
define('CIVI_PASS',   'yyyyy');

// Name of the CiviCRM database inside MySQL
define('CIVI_DBNAME', 'zzzzz');

// The following parameters define your use of the Salsa server
// through the Salsa APIs.  See http://www.salsalabs.com/devs/docs/api
// The particular server at Salsa labs housing your data
define('SALSA_URL',   'https://some-server.salsalabs.com');

// Your username (email address) on Salsa
define('SALSA_USER',  'somebody@example.com');

// Password for the above Salsa login
define('SALSA_PASS',  'OpenSesame');

// If this array is not empty, it contains a list of CiviCRM contact
// IDs to be converted.  During testing, you will probably want to use
// a list of contacts to convert, instead of trying to convert the
// whole database on every test.  The list of contacts is used to
// select donations and events to convert.  If this array is empty,
// all contacts will be converted.  For example, to convert only
// contacts with IDs 17 and 23, define $contact_ids = array(17, 23);
$contact_ids = array();
