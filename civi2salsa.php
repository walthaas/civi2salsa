<?php

/**
 *  Export CiviCRM database to Salsa
 *  
 *  Copyright (C) 2013 Walt Haas
 *  
 *  This program is free software; you can redistribute it and/or
 *  modify it under the terms of the GNU General Public License
 *  as published by the Free Software Foundation; either version 2
 *  of the License, or (at your option) any later version.
 *  
 *  This program is distributed in the hope that it will be useful,
 *  but WITHOUT ANY WARRANTY; without even the implied warranty of
 *  MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 *  GNU General Public License for more details.
 *  
 *  You should have received a copy of the GNU General Public License
 *  along with this program; if not, write to the
 *  Free Software Foundation, Inc.
 *  51 Franklin Street, Fifth Floor
 *  Boston, MA  02110-1301, USA. 
 *
 *  @todo Figure out what to do with the relationship data stored in
 *    civicrm_relationship.  There doesn't appear to be an equivalent
 *    in Salsa.
 *  @todo Populate soft_credit table
 *  @todo Split off separate password file
 */

include 'params.inc';

// List of contact id's to process.  If empty, process all contacts
global $contact_ids;

// array of CiviCRM contribution status codes
global $contribution_status;

// array of CiviCRM county codes and names
global $county;

// array of CiviCRM country codes and names
global $cntry;

// Table converting civicrm_event.id to Salsa event.event_KEY
global $event_table;

// array of CiviCRM event types
global $event_type;

// array of CiviCRM honor types
global $honor_type;

// Table converting civicrm_group.id to Salsa groups.groups_KEY
global $group_table;

// array of CiviCRM location blocks with codes
global $loc_blk;

// array of CiviCRM location types
global $loc_type;

// array of CiviCRM payment instruments with codes
global $payment_instrument;

// array of CiviCRM individual name prefixes with codes
global $prefix;

// array of relationships found in civicrm_relationship
global $relationships;

// array of CiviCRM relationship types
global $relationship_type;

// array of CiviCRM state/province codes and names
global $state;

// Open a connection to the CiviCRM database
$civi = new mysqli(CIVI_HOST, CIVI_USER, CIVI_PASS, CIVI_DBNAME);
if ($civi->connect_errno) {
  echo "Failed to connect to MySQL: " . $civi->connect_error;
  exit(1);
}

// Initialize a cURL session to the Salsa server
$curl = curl_init();
curl_setopt_array($curl,
  array(
    CURLOPT_POST           => TRUE,
    CURLOPT_RETURNTRANSFER => TRUE,
    CURLOPT_HEADER         => FALSE,
    CURLOPT_TIMEOUT        => 100,
    CURLOPT_COOKIESESSION  => TRUE,
    CURLOPT_COOKIEFILE     => '/tmp/salsa_cookies',
    CURLOPT_COOKIEJAR      => '/tmp/salsa_cookies',
    CURLOPT_USERAGENT      => 'civi2salsa',
    CURLOPT_SSL_VERIFYHOST => FALSE,
    CURLOPT_SSL_VERIFYPEER => FALSE,
    //CURLOPT_VERBOSE        => TRUE,
  )
);
$auth = array('email' => SALSA_USER, 'password' => SALSA_PASS );
curl_setopt_array($curl,
  array(
    CURLOPT_URL => SALSA_URL . '/api/authenticate.sjs',
    CURLOPT_POSTFIELDS => http_build_query($auth),
  )
);
$xml = curl_exec($curl);
$login = new SimpleXMLElement($xml);
if ($login->message != 'Successful Login') {
  echo "Login to Salsa server failed\n";
  exit(1);
}
// We don't seem to need to supply this explicitly in an API call.
// It seems to be specified in the session cookie.
$salsa_org_key = $login['organization_KEY'];
printf("Logged in to Salsa as organization %s\n", $salsa_org_key);

// Delete all supporter_groups from Salsa
delete_objects($curl, 'supporter_groups');

// Delete all supporter_event objects from Salsa
delete_objects($curl, 'supporter_event');

// Delete all supporter_household objects from Salsa
delete_objects($curl, 'supporter_household');

// Delete all household objects from Salsa
delete_objects($curl, 'household');

// Delete all event objects from Salsa
delete_objects($curl, 'event');

// Delete all soft_credit objects from Salsa
delete_objects($curl, 'soft_credit');

// Delete all donation objects from Salsa
delete_objects($curl, 'donation');

// Delete all current supporters from Salsa
delete_objects($curl, 'supporter');

// Delete all current groups from Salsa
delete_objects($curl, 'groups');

// Get contribution status codes
get_contribution_status($civi);

// Get county codes from civicrm_county
get_county($civi);

// Get country codes from civicrm_country
get_cntry($civi);

// Get event types from civicrm_option_* tables
get_event_type($civi);

// Get honor types from civicrm_option_* tables
get_honor_type($civi);

// Get location blocks from the civicrm_loc_block table 
get_loc_blk($civi);

// Get CiviCRM location types from civicrm_location_type table
get_loc_type($civi);

// Get payment instruments from civicrm_option_* tables
get_payment_instrument($civi);

// Get individual name prefix codes from civicrm_option_* tables
get_prefix($civi);

// Get relationship types from civicrm_relationship_type
get_relationship_type($civi);

// Get relationships from civicrm_relationship
get_relationships($civi);

// Get state/province codes from civicrm_state_province
get_state($civi);

// Read civicrm_group and convert to Salsa groups table
cvt_group($civi, $curl);

// Read civicrm_contact and convert to Salsa supporters table
cvt_contact($civi, $curl);

// Read civicrm_pledge and convert to Salsa recurring_donation table
//cvt_pledge($civi, $curl);

// Read civicrm_contribution_recur and convert to Salsa recurring_donation table
cvt_contribution_recur($civi, $curl);

// Convert the $relationships table to Salsa households
// and supporter_household connections
cvt_relationships($civi, $curl);

// Read civicrm_group_contact and convert to Salsa supporter_groups table
cvt_group_contact($civi, $curl);

// Read civicrm_event and convert to Salsa event table
cvt_event($civi, $curl);

// Read civicrm_participant table and convert to Salsa supporter_event table
cvt_participant($civi, $curl);

// Read civicrm_contribution table and convert to Salsa donation table
cvt_contribution($civi, $curl);

// Read civicrm_contribution_soft table and convert to Salsa soft_credit table
//cvt_contribution_soft($civi, $curl);

exit(0);

/**
 * Add a 'salsa_key' column to a table in the CiviCRM DB
 */
function add_salsa_key(mysqli $civi, $table) {

  // Check whether this table already has a 'salsa_key' column
  $query = 'SHOW COLUMNS IN ' . $table . ' FROM ' . CIVI_DBNAME;
  if (($table_cols = $civi->query($query)) === FALSE) {
    printf("Failed to '$query': %s\n", $civi->error);
    exit(1);
  }
  $salsa_key_found = FALSE;
  while ($table_col = $table_cols->fetch_assoc()) {
    if ($table_col['Field'] == 'salsa_key') {
      $salsa_key_found = TRUE;
      break;
    }
  }
  if (!$salsa_key_found) {
    // Add a salsa_key column to this table
    $query = 'ALTER TABLE ' . $table . ' ADD salsa_key INT UNSIGNED';
    if (($result = $civi->query($query)) === FALSE) {
      printf("Failed to '$query': %s\n", $civi->error);
      exit(1);
    }
  }
}

/**
 *  Add a 'salsa_key' index to a table in the CiviCRM DB
 *
 *  The table already has a salsa_key column.  Now we index it.
 */
function add_salsa_key_index(mysqli $civi, $table) {

  // Check whether this table already has a 'salsa_key' index
  $query = 'SHOW INDEX FROM ' . $table . ' IN ' . CIVI_DBNAME;
  if (($table_cols = $civi->query($query)) === FALSE) {
    printf("Failed to '$query': %s\n", $civi->error);
    exit(1);
  }
  $salsa_key_index_found = FALSE;
  while ($table_col = $table_cols->fetch_assoc()) {
    //print_r($table_col);
    if ($table_col['Key_name'] == 'salsa_key_index') {
      $salsa_key_index_found = TRUE;
      break;
    }
  }
  if (!$salsa_key_index_found) {
    // Add a salsa_key index to this table
    $query = 'ALTER TABLE ' . $table . ' ADD INDEX salsa_key_index (salsa_key)';
    if (($result = $civi->query($query)) === FALSE) {
      printf("Failed to '$query': %s\n", $civi->error);
      exit(1);
    }
  }
}

/**
 *  Drop the 'salsa_key' index from a table in the CiviCRM DB
 */
function drop_salsa_key_index(mysqli $civi, $table) {

  // Check whether this table already has a 'salsa_key' index
  $query = 'SHOW INDEX FROM ' . $table . ' IN ' . CIVI_DBNAME;
  if (($table_cols = $civi->query($query)) === FALSE) {
    printf("Failed to '$query': %s\n", $civi->error);
    exit(1);
  }
  $salsa_key_index_found = FALSE;
  while ($table_col = $table_cols->fetch_assoc()) {
    //print_r($table_col);
    if ($table_col['Key_name'] == 'salsa_key_index') {
      $salsa_key_index_found = TRUE;
      break;
    }
  }
  if ($salsa_key_index_found) {
    // Add a salsa_key index to this table
    $query = 'ALTER TABLE ' . $table . ' DROP INDEX salsa_key_index';
    if (($result = $civi->query($query)) === FALSE) {
      printf("Failed to '$query': %s\n", $civi->error);
      exit(1);
    }
  }
}

/**
 *  Convert civicrm_activity table to Salsa contact_history table
 */
function cvt_activity(mysqli $civi, $curl) {

  // Read the activity types into memory
  // First we must find the option group for activity types
  $query = "SELECT * FROM civicrm_option_group WHERE name = 'activity_type'";
  $civi_option_codes = $civi->query($query);
  if ($civi_option_codes === FALSE) {
    printf("Failed to '%s': %s\n", $query, $civi->error);
    exit(1); 
  }
  $groups = array();
  while ($civi_option_code = $civi_option_codes->fetch_assoc()) {
    $groups[] = $civi_option_code['id'];
  }
  if (count($groups) != 1) {
    echo "Couldn't find unique option group id for activity_type\n";
    exit(1);
  }
  $activity_type_group = $groups[0];

  // Using the option group for activity types we can find the
  // individual activity type codes and descriptions
  $query = "SELECT * FROM civicrm_option_value"
    . " WHERE option_group_id = $activity_type_group";
  $civi_activity_types = $civi->query($query);
  if ($civi_activity_types === FALSE) {
    printf("Failed to %s: %s\n", $query, $civi->error);
    exit(1); 
  }
  $activity_type = array();
  while ($civi_activity_type = $civi_activity_types->fetch_assoc()) {
    $activity_type[$civi_activity_type['value']] = array(
      'name'        => $civi_activity_type['name'],
      'label'       => $civi_activity_type['label'],
      'description' => $civi_activity_type['description'],
    );
  }
  //print_r($activity_type);

  // Read the activity status codes into memory
  // First we must find the option group for activity status
  $query = "SELECT * FROM civicrm_option_group WHERE name = 'activity_status'";
  $civi_option_codes = $civi->query($query);
  if ($civi_option_codes === FALSE) {
    printf("Failed to '%s': %s\n", $query, $civi->error);
    exit(1); 
  }
  $groups = array();
  while ($civi_option_code = $civi_option_codes->fetch_assoc()) {
    $groups[] = $civi_option_code['id'];
  }
  if (count($groups) != 1) {
    echo "Couldn't find unique option group id for activity_status\n";
    exit(1);
  }
  $activity_status_group = $groups[0];

  // Using the option group for activity status we can find the
  // individual activity status codes and descriptions
  $query = "SELECT * FROM civicrm_option_value"
    . " WHERE option_group_id = $activity_status_group";
  $civi_activity_statuses = $civi->query($query);
  if ($civi_activity_statuses === FALSE) {
    printf("Failed to %s: %s\n", $query, $civi->error);
    exit(1); 
  }
  $activity_status = array();
  while ($civi_activity_status = $civi_activity_statuses->fetch_assoc()) {
    $activity_status[$civi_activity_status['value']] = array(
      'name'        => $civi_activity_status['name'],
      'label'       => $civi_activity_status['label'],
      'description' => $civi_activity_status['description'],
    );
  }
  //print_r($activity_status);

  // Count contact_history items added to Salsa
  $i = 0;

  $civi_contacts = query_contacts($civi);

  // Check all contacts in CiviCRM  
  while ($civi_contact = $civi_contacts->fetch_assoc()) {

    // For this contact, find their contact history in civicrm_activity
    $query = 'SELECT * FROM civicrm_activity WHERE source_contact_id = ' .
      $civi_contact['id'];
    //printf("Query: '%s'\n", $query);
    $civi_activities = $civi->query($query);
    if ($civi_activities === FALSE) {
      printf("Failed to'%s': %s\n", $query, $civi->error);
      exit(1); 
    }
    while ($civi_activity = $civi_activities->fetch_assoc()) {
      //print_r($civi_activity);

      $salsa_contact_history = array();
      // civicrm_activity:source_contact_id is the civicrm_contact:id of the
      // logged in user, normally office staff, who took this action.

      // Processing depends on the type of activity
      //print_r($activity_type[$civi_activity['activity_type_id']]['label']);
      switch($activity_type[$civi_activity['activity_type_id']]['label']) {

        case 'Bulk Email':
          // source_record_id index to ???
          // FIXME
          break;

        case 'Contribution':
          // This is a contribution.
          // It should already be recorded in civicrm_contribution.
          if (!empty($civi_activity['source_record_id'])) {
            // We have a key into civicrm_contribution.
            // Verify that the row actually exists.
            if (!is_row_present($civi, 'civicrm_contribution',
              $civi_activity['source_record_id'])) {
              printf("civicrm_activity:id=%d has source_record_id=%d" .
                " not found in civicrm_contribution\n",
                $civi_activity['id'], $civi_activity['source_record_id']);
	    }
            // This contribution activity is recorded in civicrm_contribution
            // so we don't convert it to the Salsa contact_history
	  }
          else {
            printf("civicrm_activity:id=%d has empty source_record_id\n",
              $civi_activity['id']);
	  }
          break;

        case 'Email':
          // FIXME
          break;

        case 'Event Registration':
          // source_record_id if present should index civicrm_particpant.
          // Multiple records may exist with this activity type and the
          // same value in source_record_id.  The person who actually
          // participated is shown in civicrm_particpant:contact_id
          if (!empty($civi_activity['source_record_id'])) {
            // We have a key into civicrm_participant.
            // Verify that the row actually exists.
            if (!is_row_present($civi, 'civicrm_participant',
              $civi_activity['source_record_id'])) {
              printf("civicrm_activity:id=%d has source_record_id=%d" .
                " not found in civicrm_participant\n",
                $civi_activity['id'], $civi_activity['source_record_id']);
	    }
            // We have the participant row for this event registration.
            // The actual contact history is converted in cvt_participant
	  }
          else {
            printf("civicrm_activity:id=%d has empty source_record_id\n",
              $civi_activity['id']);
	  }
          // FIXME
          break;

        // Save Our Canyons does not actually use any of these
        // so we just don't bother with them
        case 'Assign Case Role':
        case 'Canvass':
        case 'Change Case Start Date':
        case 'Change Case Tags':
        case 'Email correspondence':
        case 'Follow up':
        case 'Inbound Email':
        case 'Letter of Inquiry':
        case 'Link Cases':
        case 'Meeting':
        case 'Membership Renewal':
        case 'Membership Renewal Reminder':
        case 'Membership Signup':
        case 'Merge Case':
        case 'Petition':
        case 'Phone Call':
        case 'PhoneBank':
        case 'Pledge Acknowledgment':
        case 'Pledge Reminder':
        case 'Print PDF Letter':
        case 'Proposal':
        case 'Reassigned Case':
        case 'Report':
        case 'Remove Case Role':
        case 'Send Thank You':
        case 'Survey':
        case 'Tell a Friend':
        case 'Text Message (SMS)':
        case 'Volunteer':
        case 'WalkList':
        default:
      }
    }
  }
}

/**
 *  Convert civicrm_group to Salsa groups table
 *
 *  The contents of the civicrm_group table are converted and 
 *  stored into the Salsa groups table.  Additionally, the
 *  mapping from civicrm_group:id to groups:group_KEY
 *  is stored in global array $group_table for fast access.
 *
 *    Uses foreign key:
 *      parent_KEY
 *      query_KEY
 *      join_email_trigger_KEYS
 *
 *    Provides key:
 *      groups_KEY
 *
 *  @todo Finish email info
 */
function cvt_group(mysqli $civi, $curl) {

  global $group_table;
  $group_table = array();

  // Add a 'salsa_key' column to the civicrm_group table
  add_salsa_key($civi, 'civicrm_group');  

  // Drop the salsa_key_index from this table
  drop_salsa_key_index($civi, 'civicrm_group');

  if (($civi_groups = $civi->query('SELECT * FROM civicrm_group')) === FALSE) {
    printf("Failed to SELECT * FROM civicrm_group: %s\n", $civi->error);
    exit(1);
  }

  printf("Adding %d groups to Salsa ...", $civi_groups->num_rows);
  while ($civi_group = $civi_groups->fetch_assoc()) {
    $salsa_groups = array();
    $salsa_groups['Group_Name']  = $civi_group['name'];
    // What is salsa 'Reference_Name'?
    $salsa_groups['Description'] = $civi_group['description'];
    // civicrm_group does not have notes
    // Does salsa 'Display_To_User' map from civi 'visibility'?
    // Save Our Canyons doesn't use the parents and children feature
    // of civicrm_group so we don't bother with it.
    //$salsa_groups['Listserve_Type'] = ?
    //$salsa_groups['Subscription_Type'] = ?
    //$salsa_groups['Manager'] = ?
    //$salsa_groups['Moderator_Emails'] = ?
    //$salsa_groups['Subject_Prefix'] = ?
    //$salsa_groups['Listserve_Responses'] = ?
    //$salsa_groups['Append_Header'] = ?
    //$salsa_groups['Append_Footer'] = ?
    //$salsa_groups['Custom_Headers'] = ?
    //$salsa_groups['Listserve_Options'] = ?
    //$salsa_groups['external_ID'] = ?
    //$salsa_groups['From_Email'] = ?
    //$salsa_groups['From_Name'] = ?
    //$salsa_groups['Reply_To'] = ?
    //$salsa_groups['Headers_To_Remove'] = ?
    //$salsa_groups['Confirmation_Message'] = ?
    //$salsa_groups['Auto_Update'] = ?
    //$salsa_groups['query_KEY'] = ?
    //$salsa_groups['Smart_Group_Options'] = ?
    //$salsa_groups['Smart_Group_Error'] = ?
    //$salsa_groups['enable_statistics'] = ?
    //$salsa_groups['join_email_trigger_KEYS'] = ?
    $salsa_key = save_salsa($curl, 'groups', $salsa_groups);
    $query = "UPDATE civicrm_group SET salsa_key = $salsa_key WHERE id = "
      . $civi_group['id']; 
    if (($result = $civi->query($query)) === FALSE) {
      printf("Failed to %s: %s\n", $query, $civi->error);
      exit(1);
    }
    $group_table[$civi_group['id']] = $salsa_key;
  }
  //print_r($group_table);
  // Add an index on salsa_key to this table
  add_salsa_key_index($civi, 'civicrm_group');
  echo " done\n";
}

/**
 *  Convert civicrm_contact table to Salsa supporters table
 *
 *  The civicrm_contact table stores names in multiple ways:
 *    sort_name column:
 *      Name used for sorting different contact types
 *    display_name column:
 *      Formatted name representing preferred format for
 *      display/printer/other output.
 *    nick_name column:
 *      Nick Name.
 *    legal_name column:
 *      Legal Name.
 *    first_name, middle_name, last_name, prefix_id, suffix_id columns
 *    organization_name column
 *  These various forms of names may be used inconsistently.
 *  In contrast the Salsa supporter table has
 *    Title column
 *    First_Name column
 *    MI column
 *    Last_Name column
 *    Suffix column
 *  So part of what we do here is try to figure out where to put the various
 *  pieces of civicrm_contact name into the Salsa supporter table.
 *
 *  The civicrm_email table can store an indefinite number of email addresses
 *  per contact.  Each email address is stored with additional information
 *  that categorizes that address in various ways.  In contrast the Salsa
 *  supporter table stores up to two email addresses.  We find that, if we
 *  throw away all of the category information in civicrm_email, in the case
 *  of Save Our Canyons each contact has at most two unique email addresses so
 *  a direct conversion is possible.
 *
 *  @todo Handle organization type contacts correctly
 */
function cvt_contact(mysqli $civi, $curl) {

  global $cntry, $county, $loc_type, $state, $prefix, $relationships;

  // Keep track of how many contacts have more than 2 unique emails
  $multi_emails = 0;

  // Add a 'salsa_key' column to civicrm_contact
  add_salsa_key($civi, 'civicrm_contact');

  // Drop the salsa_key_index from this table
  drop_salsa_key_index($civi, 'civicrm_contact');

  // Read the civicrm_contact table and convert to Salsa supporter table
  $civi_contacts = query_contacts($civi);
  printf("Adding %d supporters to Salsa ...", $civi_contacts->num_rows);
  while ($civi_contact = $civi_contacts->fetch_assoc()) {
    $salsa_supporter = array();
    $salsa_supporter['key'] = 0;
    // Date_Created and Last_Modified will be set by the Salsa server
    // when we save this.
    $salsa_supporter['Title'] = !empty($civi_contact['prefix_id']) 
      ? $prefix[$civi_contact['prefix_id']] : NULL;;
    if ($civi_contact['contact_type'] == 'Individual') {
      $salsa_supporter['First_Name'] = $civi_contact['first_name'];
      if (!empty($civi_contact['middle_name'])) {
        $salsa_supporter['MI'] = substr($civi_contact['middle_name'], 0, 1);
      }
      $salsa_supporter['Last_Name']  = $civi_contact['last_name'];
    }
    elseif ($civi_contact['contact_type'] == 'Organization') {
      $org_name  = $civi_contact['organization_name'];
      if (!empty($org_name)) {
        // FIXME: right place to put this?
        $salsa_supporter['First_Name'] = $org_name;
      }
      else {
        printf("Organization contact %d has null organization_name\n",
	       $civi_contact['id']);
      }
    }
    else {
      printf("Contact %d has unexpected contact_type '%s'\n",
        $civi_contact['id'], $civi_contact['contact_type']);
    }

    // Name suffix not used by Save Our Canyons
    //$salsa_supporter['Suffix'] = ?;

    // As of 3.3.5 CiviCRM did not have a contact password
    //$salsa_supporter['Password'] = ?;
    $salsa_supporter['Receive_Email'] =
      $civi_contact['do_not_email'] ? 0 : 1;
    $salsa_supporter['Email_Preference'] =
      ($civi_contact['preferred_mail_format'] == 'Text')
      ? 'Text Email' : 'HTML Email';
    //$salsa_supporter['Last_Bounce'] = ?;
    $salsa_supporter['Receive_Mail'] =
      $civi_contact['do_not_mail'] ? 0 : 1;
    $salsa_supporter['Receive_Phone_Blasts'] =
      $civi_contact['do_not_phone'] ? 0 : 1;
    // As of 3.3.5 CiviCRM did not have a cell phone category
    //$salsa_supporter['Cell_Phone'] = ?;
    //$salsa_supporter['Phone_Provider'] = ?;
    //$salsa_supporter['Pager'] = ?;
    // As of 3.3.5 CiviCRM did not have FAX numbers
    //$salsa_supporter['Home_Fax'] = ?;
    //$salsa_supporter['Work_Fax'] = ?;
    //$salsa_supporter['PRIVATE_Zip_Plus_4'] = ?;
    //$salsa_supporter['District'] = ?;
    //$salsa_supporter['Organization'] = ?;
    //$salsa_supporter['Department'] = ?;
    //$salsa_supporter['Occupation'] = ?;
    //$salsa_supporter['Instant_Messenger_Service'] = ?;
    //$salsa_supporter['Instant_Messenger_Name'] = ?;
    //$salsa_supporter['Web_Page'] = ?;
    // save the id from civicrm_contact
    //$salsa_supporter['Other_Data_1'] = ?;
    //$salsa_supporter['Other_Data_2'] = ?;
    //$salsa_supporter['Other_Data_3'] = ?;
    //$salsa_supporter['Notes'] = ?;
    //$salsa_supporter['Source'] = ?;
    //$salsa_supporter['Source_Details'] = ?;
    //$salsa_supporter['Source_Tracking_Code'] = ?;
    //$salsa_supporter['Tracking_Code'] = ?;
    //$salsa_supporter['Status'] = ?;
    $salsa_supporter['uid'] = $civi_contact['id'];
    // civicrm_contact uses the xx_XX form of language code.
    // Salsa stores only the first three characters.
    $salsa_supporter['Language_Code'] = 'eng';

    // Get this contact's phones from civicrm_phone
    $civi_phones = $civi->query('SELECT * FROM civicrm_phone
      WHERE contact_id = ' . $civi_contact['id']);
    if ($civi_phones === FALSE) {
      printf("Failed to SELECT * FROM civicrm_phone WHERE"
        . " contact_id = %s: %s\n", $civi->contact['id'], $civi->error);
      exit(1); 
    }
    while ($civi_phone = $civi_phones->fetch_assoc()) {
      $loc = $loc_type[$civi_phone['location_type_id']];
      if ($loc == 'Work') {
        $salsa_supporter['Work_Phone'] = $civi_phone['phone'];
      }
      else {
        $salsa_supporter['Phone'] = $civi_phone['phone'];
      }
    }

    // Get this contact's addresses from civicrm_address
    $civi_addresses = $civi->query('SELECT * FROM civicrm_address
      WHERE contact_id = ' . $civi_contact['id']);
    if ($civi_addresses === FALSE) {
      printf("Failed to SELECT * FROM civicrm_address WHERE"
        . " contact_id = %s: %s\n", $civi->contact['id'], $civi->error);
      exit(1); 
    }
    $addr_ary = array();
    while ($civi_address = $civi_addresses->fetch_assoc()) {
      // Ignore any address without a street address or city
      if (empty($civi_address['street_address'])
        || empty($civi_address['city'])) {
        continue;
      }
      $addr_ary[] = array(
        'addr_id'        => $civi_address['id'],
        'is_primary'     => $civi_address['is_primary'],
        'loc_type'       => $civi_address['location_type_id'],
        'street_address' => $civi_address['street_address'],
        'sup_addr_1'     => $civi_address['supplemental_address_1'],
        'sup_addr_2'     => $civi_address['supplemental_address_2'],
        'city'           => $civi_address['city'],
        'county_id'      => $civi_address['county_id'],
        'state_id'       => $civi_address['state_province_id'],
        'postal_code'    => $civi_address['postal_code'],
        'country_id'     => $civi_address['country_id'],
        'latitude'       => $civi_address['geo_code_1'],
        'longitude'      => $civi_address['geo_code_2'],
        'timezone'       => $civi_address['timezone'],
      );
    }
    // Figure out if we have 0, 1 or more addresses and Do Something Sensible
    if (count($addr_ary) == 1) {
      // We have exactly one address for this contact.
      $salsa_supporter['Street'] = $addr_ary[0]['street_address'];
      $salsa_supporter['Street_2'] = $addr_ary[0]['sup_addr_1'];
      $salsa_supporter['Street_3'] = $addr_ary[0]['sup_addr_2'];
      $salsa_supporter['City']   = $addr_ary[0]['city'];
      $salsa_supporter['County'] =  !empty($addr_ary[0]['county_id']) 
        ? $county[$addr_ary[0]['county_id']] : NULL;;
      $salsa_supporter['State']  = !empty($addr_ary[0]['state_id']) 
        ? $state[$addr_ary[0]['state_id']] : NULL;
      $salsa_supporter['Country']  = !empty($addr_ary[0]['country_id'])
        ? $cntry[$addr_ary[0]['country_id']] : NULL;
      $salsa_supporter['Timezone'] = 'America/Denver';
    }
    elseif (count($addr_ary) > 1) {
      // We have more than one address for this contact.
      // Use the primary address.
      $primary_found = FALSE;
      foreach($addr_ary as $addr) {
        if ($addr['is_primary']) {
          if ($primary_found) {
            printf("address %d has more than one primary address\n",
	      $addr['addr_id']);
          }
          else {
            $primary_found = TRUE;
            $salsa_supporter['Street'] = $addr['street_address'];
            $salsa_supporter['Street_2'] = $addr['sup_addr_1'];
            $salsa_supporter['Street_3'] = $addr['sup_addr_2'];
            $salsa_supporter['City']   = $addr['city'];
            $salsa_supporter['County'] =  !empty($addr['county_id']) 
              ? $county[$addr['county_id']] : NULL;;
            $salsa_supporter['State']  = !empty($addr['state_id']) 
              ? $state[$addr['state_id']] : NULL;
            $salsa_supporter['Country']  = !empty($addr['country_id'])
              ? $cntry[$addr['country_id']] : NULL;
            $salsa_supporter['Timezone'] = 'America/Denver';
          }
        }
      }
      if (!$primary_found) {
        printf("contact %d has no primary address\n",
          $civi_contact['id']);
      }
    }

    $salsa_key = save_salsa($curl, 'supporter', $salsa_supporter,
      $civi_contact);
    $query = "UPDATE civicrm_contact SET salsa_key = $salsa_key WHERE id = "
      . $civi_contact['id']; 
    if (($result = $civi->query($query)) === FALSE) {
      printf("Failed to %s: %s\n", $query, $civi->error);
      exit(1);
    }

    // Get this contact's emails from civicrm_email
    $civi_emails = $civi->query('SELECT * FROM civicrm_email
      WHERE contact_id = ' . $civi_contact['id']);
    if ($civi_emails === FALSE) {
      printf("Failed to SELECT * FROM civicrm_emails WHERE"
        . " contact_id = %s: %s\n", $civi->contact['id'], $civi->error);
      exit(1); 
    }

    // Find unique email addresses by discarding category information
    $email_ary = array();
    while ($civi_email = $civi_emails->fetch_assoc()) {
      $email_ary[$civi_email['email']] = TRUE;
    }
    $emails = array_keys($email_ary);
    // Figure out if we have 0, 1, 2 or more emails and Do Something Sensible
    $url = NULL;
    if (count($emails) > 0) {
      $url = SALSA_URL . '/save?xml&object=supporter&key='
        . $salsa_key . '&Email=' . urlencode($emails[0]);
    }
    if (count($emails) > 1) {
      $url .= '&Alternative_Email=' . urlencode($emails[1]);
    }
    if (count($emails) > 2) {
      // More than two emails, we're losing information
      printf("CiviCRM contact %d has more than two emails after"
        . " consolidation\n", $civi_contact['id']);
      $multi_emails++;
    }
    if (!empty($url)) {
      //printf("URL: %s", $url);
      curl_setopt_array($curl,
        array(
          CURLOPT_URL        => $url,
          CURLOPT_HTTPGET    => TRUE,
        )
      );
      $xml = curl_exec($curl);
      $response = new SimpleXMLElement($xml);
      if ($response->error) {    
        printf("Failed to store email(s) for contact %d: %s\n",
          $civi_contact['id'], $response->error);
        echo "URL: $url\n";
        echo "XML: "; print_r($xml); echo "\n";
        exit(1);
      }
    }

    // If this contact is in a domestic relationship, add their Salsa
    // key to their entry in $relationships
    foreach ($relationships as $rid => $relationship) {
      if (array_key_exists($civi_contact['id'], $relationship['members'])) {
        $relationships[$rid]['members'][$civi_contact['id']] = $salsa_key;
        break;
      }
    }
  }

  // Add an index on salsa_key to this table
  add_salsa_key_index($civi, 'civicrm_contact');

  echo " done\n";
  //print_r($relationships);
  if ($multi_emails) {
    printf("%d contacts have more than two emails\n", $multi_emails);
  }
}

/**
 *  Convert civicrm_contribution table to Salsa Donation table
 *
 *  Uses foreign keys:
 *    supporter_KEY
 *    event_KEY
 *    donate_page_KEY
 *    recurring_donation_KEY
 *    membership_invoice_KEY
 *    referral_supporter_KEY
 *    merchant_account_KEY
 *    event_fee_KEY
 *
 *  Provides key:
 *    donation_KEY
 */
function cvt_contribution(mysqli $civi, $curl) {

  global $honor_type, $payment_instrument;

  echo "Adding donations to Salsa\n";

  // Add a 'salsa_key' column to civicrm_contribution
  add_salsa_key($civi, 'civicrm_contribution');

  // Drop the salsa_key_index from this table
  drop_salsa_key_index($civi, 'civicrm_contribution');

  // Read the civicrm_contribution_type table into memory
  $contribution_types = array();
  $query = 'SELECT * FROM civicrm_contribution_type';
  $civi_contribution_types = $civi->query($query);
  if ($civi_contribution_types === FALSE) {
    printf("Failed to '$query': %s\n", $civi->error);
    exit(1); 
  }
  while ($civi_contribution_type = $civi_contribution_types->fetch_assoc()) {
    $contribution_types[$civi_contribution_type['id']] =
      $civi_contribution_type;
  }
  //print_r($contribution_types);

  // Count donations added to Salsa
  $i = 0;

  // Check all contacts in CiviCRM  
  $civi_contacts = query_contacts($civi);
  while ($civi_contact = $civi_contacts->fetch_assoc()) {

    // For this contact, find their contributions in civicrm_contribution
    $query = 'SELECT * FROM civicrm_contribution WHERE contact_id = ' .
      $civi_contact['id'];
    //printf("Query: '%s'\n", $query);
    $civi_contributions = $civi->query($query);
    if ($civi_contributions === FALSE) {
      printf("Failed to'%s': %s\n", $query, $civi->error);
      exit(1); 
    }
    while ($civi_contribution = $civi_contributions->fetch_assoc()) {
      //print_r($civi_contribution);
      // Ignore test data
      if ($civi_contribution['is_test']) {
        continue;
      }

      $salsa_donation = array();
      $salsa_donation['supporter_KEY'] = $civi_contact['salsa_key'];
      //$salsa_donation['event_KEY'] = ?;
      //$salsa_donation['donate_page_KEY'] = ?;
      if ($civi_contribution['contribution_recur_id']) {
        // This is part of a recurring donation.  Find the foreign
        // key to the recurring_donation table.
        $query = 'SELECT * FROM civicrm_contribution_recur WHERE id = '
          . $civi_contribution['contribution_recur_id'];
        $recur = query_unique($civi, $query);
        if ($recur) {        
          $salsa_donation['recurring_donation_KEY'] = $recur['salsa_key'];
        }
      }
      //$salsa_donation['membership_invoice_KEY'] = ?;
      $salsa_donation['Transaction_Date'] = $civi_contribution['receive_date'];
      //$salsa_donation['Date_Entered'] = ?;
      $salsa_donation['amount'] = $civi_contribution['total_amount'];
      $salsa_donation['Currency_Code'] = $civi_contribution['currency'];
      if (!empty($civi_contribution['contribution_type_id'])) {
        switch ($contribution_types[$civi_contribution[
          'contribution_type_id']]['name']) {
        case 'Auction':
        case 'Sales':
          $salsa_donation['Transaction_Type'] = 'Purchase';
          $salsa_donation['Status'] = 'Order Fulfilled';
          break;

        case 'Event fee':
          $salsa_donation['Transaction_Type'] = 'Event Fee';
          $salsa_donation['Status'] = 'Event Fee';
          break;

        case 'Grant':
          $salsa_donation['Transaction_Type'] = 'Donation';
          $salsa_donation['Status'] = 'Donation Only';
          break;

        case 'In-Kind':
          $salsa_donation['Transaction_Type'] = 'In-Kind';
          break;

        case 'Membership':
          $salsa_donation['Transaction_Type'] = 'Renewal';
          break;

        default:
          $salsa_donation['Transaction_Type'] = 'Other';
          break;
        }
      }
      // If this is part of a recurring contribution, we mark the
      // transaction type as 'Recurring' so it will show up on the
      // recurring donations page in Salsa.
      if (!empty($salsa_donation['recurring_donation_KEY'])) {
        $salsa_donation['Transaction_Type'] = 'Recurring'; 
      }
      if (!empty($civi_contribution['payment_instrument_id'])) {
        switch ($payment_instrument[$civi_contribution[
          'payment_instrument_id']]) {
        case 'Cash':
          $salsa_donation['Form_Of_Payment'] = 'Cash';
          break;

        case 'Check':
          $salsa_donation['Form_Of_Payment'] = 'Check';
          $salsa_donation['Order_Info'] = $civi_contribution['check_number'];
          break;

        case 'Credit Card':
        case 'Debit Card':
          $salsa_donation['Form_Of_Payment'] = 'Credit Card';
          $salsa_donation['PNREF'] = $civi_contribution['trxn_id'];
          break;

        case 'EFT':
          $salsa_donation['Form_Of_Payment'] = 'Other';
          break;

        default:
          $salsa_donation['Form_Of_Payment'] = 'Unknown';
        }
      }
      else {
        $salsa_donation['Form_Of_Payment'] = 'Unknown';
      }
      //$salsa_donation['cc_type'] = ?;
      //$salsa_donation['Credit_Card_Digits'] = ?;
      //$salsa_donation['Credit_Card_Expiration'] = ?;
      //$salsa_donation['PNREF'] = ?;
      //$salsa_donation['RESULT'] = ?;
      //$salsa_donation['RESPMSG'] = ?;
      //$salsa_donation['AUTHCODE'] = ?;
      //$salsa_donation['AVS'] = ?;
      //$salsa_donation['Order_Info'] = ?;
      //$salsa_donation['Disbursement_Status'] = ?;
      //$salsa_donation['Responsible_Party'] = ?;
      //$salsa_donation['Date_Fulfilled'] = ?;
      //$salsa_donation['PRIVATE_Complete_Summary'] = ?;
      if ($civi_contact['contact_type'] == 'Individual') {
        $salsa_donation['First_Name'] = $civi_contact['first_name'];
        $salsa_donation['Last_Name'] = $civi_contact['last_name'];
      }
      elseif ($civi_contact['contact_type'] == 'Organization') {
        $salsa_donation['Last_Name'] = $civi_contact['organization_name'];
      }
      //$salsa_donation['Email'] = ?;
      //$salsa_donation['Tracking_Code'] = ?;
      //$salsa_donation['Donation_Tracking_Code'] = ?;
      //$salsa_donation['Tax_Status'] = ?;
      //$salsa_donation['Designation_Code'] = ?;
      //$salsa_donation['PRIVATE_Donation_Source'] = ?;
      //$salsa_donation['Note'] = ?;
      if (!empty($civi_contribution['thankyou_date'])) {
        $salsa_donation['Thank_You_Sent'] = 1;
        $salsa_donation['Thank_Date'] = $civi_contribution['thankyou_date'];
      }
      //$salsa_donation['referral_supporter_KEY'] = ?;
      //$salsa_donation['merchant_account_KEY'] = ?;
      //$salsa_donation['IP_Address'] = ?;
      if (!empty($civi_contribution['honor_type_id'])) {
        // This donation is in honor or memory of somebody
        // First, try to find who is being honored
        if (!empty($civi_contribution['honor_contact_id'])) {
          $query = 'SELECT * FROM civicrm_contact WHERE id =' .
	    $civi_contribution['honor_contact_id'];
          if ($honored = query_unique($civi, $query)) {
            switch ($honor_type[$civi_contribution['honor_type_id']]['label']) {
              case 'In Honor of':
                $salsa_donation['In_Honor_Name'] = $honored['display_name'];
                //$salsa_donation['In_Honor_Email'] = ?;
                //$salsa_donation['In_Honor_Address'] = ?;
                break;

              case 'In Memory of':
                $salsa_donation['In_Memory_Name'] = $honored['display_name'];
                break;

              default:
            }
          }
        }
      }
      $salsa_donation['uid'] = $civi_contribution['id'];
      //$salsa_donation['Batch_Code'] = ?;
      //$salsa_donation['VARCHAR0'] = ?;
      //$salsa_donation['VARCHAR1'] = ?;
      //$salsa_donation['VARCHAR2'] = ?;
      //$salsa_donation['event_fee_KEY'] = ?;
      //$salsa_donation['Employer'] = ?;
      //$salsa_donation['Occupation'] = ?;
      //$salsa_donation['Employer_Street'] = ?;
      //$salsa_donation['Employer_Street_2'] = ?;
      //$salsa_donation['Employer_City'] = ?;
      //$salsa_donation['Employer_State'] = ?;
      //$salsa_donation['Employer_Zip'] = ?;
      $salsa_key = save_salsa($curl, 'donation', $salsa_donation,
        $civi_contribution);
      $query = "UPDATE civicrm_contribution SET salsa_key = $salsa_key
        WHERE id = " . $civi_contribution['id']; 
      if (($result = $civi->query($query)) === FALSE) {
        printf("Failed to %s: %s\n", $query, $civi->error);
        exit(1);
      }
      $i++;
    }
  }

  // Add an index on salsa_key to this table
  add_salsa_key_index($civi, 'civicrm_contribution');

  printf("Added %d donations to Salsa\n", $i);
}

/**
 *  Convert civicrm_contribution_recur table to Salsa recurring_donation table
 */
function cvt_contribution_recur(mysqli $civi, $curl) {

  global $contribution_status;

  // Add a 'salsa_key' column to civicrm_contribution_recur
  add_salsa_key($civi, 'civicrm_contribution_recur');

  // Drop the salsa_key_index from this table
  drop_salsa_key_index($civi, 'civicrm_contribution_recur');

  // Count recurring donations added to Salsa
  $i = 0;

  // Date right now
  $now = new DateTime();

  // Check all contacts in CiviCRM  
  $civi_contacts = query_contacts($civi);
  while ($civi_contact = $civi_contacts->fetch_assoc()) {

    // For this contact, find their recurring contributions in
    // civicrm_contribution_recur
    $query = 'SELECT * FROM civicrm_contribution_recur WHERE contact_id = ' .
      $civi_contact['id'];
    //printf("Query: '%s'\n", $query);
    $civi_contribs_recur = $civi->query($query);
    if ($civi_contribs_recur === FALSE) {
      printf("Failed to'%s': %s\n", $query, $civi->error);
      exit(1); 
    }
    while ($civi_contrib_recur = $civi_contribs_recur->fetch_assoc()) {
      if ($civi_contrib_recur['is_test']) {
        continue;
      }
      $salsa_recurring_donation = array();
      $salsa_recurring_donation['supporter_KEY'] = $civi_contact['salsa_key'];
      //$salsa_recurring_donation['donation_KEY'] = ?;
      //$salsa_recurring_donation['merchant_account_KEY'] = ?;
      //$salsa_recurring_donation['Transaction_Date'] = ?;
      //$salsa_recurring_donation['RPREF'] = ?;
      //$salsa_recurring_donation['TRXPNREF'] = ?;
      //$salsa_recurring_donation['RESULT'] = ?;
      //$salsa_recurring_donation['PROFILEID'] = ?;
      //$salsa_recurring_donation['RESPMSG'] = ?;
      $salsa_recurring_donation['amount'] =
        $civi_contrib_recur['amount'];
      $salsa_recurring_donation['Start_Date'] =
        $civi_contrib_recur['start_date'];
      switch ($civi_contrib_recur['frequency_unit']) {
        case 'day':
          // Save Our Canyons doesn't use this
          break;

        case 'week':
          // Save Our Canyons doesn't use this
          break;

        case 'month':
          switch ($civi_contrib_recur['frequency_interval']) {
            case '1':
              $salsa_recurring_donation['PAYPERIOD'] = 'MONT';
              break;

            case '2':
              printf("Oops, recurring donation %d every two months\n",
                $civi_contrib_recur['id']);
              break;

            case '3':
              $salsa_recurring_donation['PAYPERIOD'] = 'QTER';
              break;

            case '6':
              $salsa_recurring_donation['PAYPERIOD'] = 'SMYR';
              break;

            case '12':
              $salsa_recurring_donation['PAYPERIOD'] = 'YEAR';
              break;

            default:
	  }
          break;

        case 'year':
          // Save Our Canyons doesn't use this
          break;

        default:
      }


      $salsa_recurring_donation['Term'] =
        $civi_contrib_recur['installments'];
      //$salsa_recurring_donation['Tracking_Code'] = ?;
      //$salsa_recurring_donation['Designation_Code'] = ?;
      //$salsa_recurring_donation['Email'] = ?;
      $salsa_recurring_donation['First_Name'] = $civi_contact['first_name'];
      $salsa_recurring_donation['Last_Name'] = $civi_contact['last_name'];
      //$salsa_recurring_donation['Tax_Status'] = ?;
      
      switch ($contribution_status[$civi_contrib_recur['contribution_status_id']]) {
      case 'Cancelled':
	break;

      case 'Completed':
	break;

      case 'Failed':
	break;

      case 'In Progress':
	break;

      case 'Overdue':
	break;

      case 'Pending':
        // This is the only code Save Our Canyons actually uses
        $salsa_recurring_donation['Status'] = 'Active';
        
	break;

      default:
      }
      // We need to figure out whether this recurring contribution is still
      // active or has completed.  Save Our Canyons does not store an end
      // date so we need to compute that from the start date, interval and
      // number of installments if not unlimited.
      if (!empty($civi_contrib_recur['installments'])
          && ($civi_contrib_recur['installments'] > 1)) {
        // This recurring contribution has a limited number of installments
        $start = new DateTime($civi_contrib_recur['start_date']);
        //echo "civicrm_contribution_recur " .
        //  $civi_contrib_recur['id'] . ":\n";
        //echo "  start date: " . $start->format('Y-m-d') .  "\n";
        //echo "  installments: " . $civi_contrib_recur['installments'] ."\n";
        //echo "  interval: " . $civi_contrib_recur['frequency_interval'] .
        //  " months\n";
        $duration = new DateInterval('P' .
          (string) ($civi_contrib_recur['installments'] - 1) *
          $civi_contrib_recur['frequency_interval']  . 'M');
        //echo "  duration: " . $duration->format('%m') . " months\n";
        $last =  $start->add($duration);
        //echo "  last date: " . $last->format('Y-m-d') .  "\n";
        // If today is after the date of the last installment, the
        // pledge is complete.  'Inactive' is the nearest thing Salsa
        // offers so change the status to 'Inactive'.
        if ($now > $last) {
          //echo "  marking inactive\n";
          $salsa_recurring_donation['Status'] = 'Inactive';
        }
      }

      //$salsa_recurring_donation['Note'] = ?;
      //$salsa_recurring_donation['Error_Message'] = ?;
      //$salsa_recurring_donation['source_donation_KEY'] = ?;
      //$salsa_recurring_donation['current_reference_donation_KEY'] = ?;
      //$salsa_recurring_donation['run_one_transaction'] = ?;
      //$salsa_recurring_donation['donate_page_KEY'] = ?;
      //$salsa_recurring_donation['cc_type'] = ?;
      //$salsa_recurring_donation['Credit_Card_Expiration'] = ?;
      //echo "civi_contrib_recur:\n"; print_r($civi_contrib_recur);
      //echo "salsa_recurring_donation:\n"; print_r($salsa_recurring_donation);
      //echo "\n";
      $salsa_key = save_salsa($curl, 'recurring_donation',
        $salsa_recurring_donation, $civi_contrib_recur);
      //echo "salsa_key: $salsa_key\n";
      $query = "UPDATE civicrm_contribution_recur SET salsa_key = $salsa_key
        WHERE id = " . $civi_contrib_recur['id']; 
      if (($result = $civi->query($query)) === FALSE) {
        printf("Failed to %s: %s\n", $query, $civi->error);
        exit(1);
      }
      $i++;
    }
  }

  // Add an index on salsa_key to this table
  add_salsa_key_index($civi, 'civicrm_contribution_recur');

  printf("Converted %d recurring contributions from Civi to Salsa\n", $i);
}

/**
 *  Convert civicrm_contribution_soft table to Salsa soft_credit table
 */
function cvt_contribution_soft(mysqli $civi, $curl) {

  // Count soft credits added to Salsa
  $i = 0;

  // Check all contacts in CiviCRM  
  $civi_contacts = query_contacts($civi);
  while ($civi_contact = $civi_contacts->fetch_assoc()) {

    // For this contact, find their soft contributions in
    // civicrm_contribution_soft
    $query = 'SELECT * FROM civicrm_contribution_soft WHERE contact_id = ' .
      $civi_contact['id'];
    printf("Query: '%s'\n", $query);
    $civi_contribs_soft = $civi->query($query);
    if ($civi_contribs_soft === FALSE) {
      printf("Failed to'%s': %s\n", $query, $civi->error);
      exit(1); 
    }
    while ($civi_contrib_soft = $civi_contribs_soft->fetch_assoc()) {
      print_r($civi_contrib_soft);

      // Find the original contribution
      $query = 'SELECT * FROM civicrm_contribution WHERE id = ' .
        $civi_contrib_soft['contribution_id'];
      $civi_contribution = query_unique($civi, $query);
      if (!$civi_contribution) {
        // Can't find the original hard contribution so forget it
        continue;
      }

      // Find the source of the original contribution
      $query = 'SELECT * FROM civicrm_contact WHERE id = ' .
        $civi_contribution['contact_id'];
      $civi_orig_contact = query_unique($civi, $query);
      if (!$civi_orig_contact) {
        // Can't find the source of the original contribution, forget it
        continue;
      }
      $salsa_soft_credit = array();
      $salsa_soft_credit['donation_KEY'] = $civi_contribution['salsa_key'];
      $salsa_soft_credit['originating_supporter_KEY'] =
        $civi_orig_contact['salsa_key'];
      $salsa_soft_credit['supporter_KEY'] = $civi_contact['salsa_key'];
      $salsa_soft_credit['amount'] = $civi_contrib_soft['amount'];
      //$salsa_soft_credit['soft_credit_type'] = ?;
      //$salsa_soft_credit['First_Name'] = ?;
      //$salsa_soft_credit['MI'] = ?;
      //$salsa_soft_credit['Last_Name'] = ?;
      //$salsa_soft_credit['Email'] = ?;
      //$salsa_soft_credit['Employer_Matching_Gift_Percent'] = ?;
      //$salsa_soft_credit['Company_Name'] = ?;
      //$salsa_soft_credit['Street'] = ?;
      //$salsa_soft_credit['Street_2'] = ?;
      //$salsa_soft_credit['City'] = ?;
      //$salsa_soft_credit['State'] = ?;
      //$salsa_soft_credit['Zip'] = ?;
      //$salsa_soft_credit['Phone'] = ?;
      //$salsa_soft_credit['Contact_Name'] = ?;
      //$salsa_soft_credit['Contact_Email'] = ?;
      echo "civi_contrib_soft:\n"; print_r($civi_contrib_soft);
      echo "salsa_soft_credit:\n"; print_r($salsa_soft_credit);
      echo "\n";
      $salsa_key = save_salsa($curl, 'soft_credit', $salsa_soft_credit,
        $civi_contrib_soft);
      echo "salsa_key: $salsa_key\n";
      $i++;
    }
  }
  printf("Added %d soft credits to Salsa\n", $i);
}

/**
 *  Convert civicrm_group_contact table to Salsa supporter_groups table
 *
 *  Uses foreign keys:
 *    supporter_KEY
 *    groups_KEY
 *
 *  Provides key:
 *    supporter_groups_KEY
 *
 *  @todo Figure out whether we need to add any group memberships based
 *    on contact type, relationship or whatever.
 */
function cvt_group_contact(mysqli $civi, $curl) {

  global $group_table;

  // Count supporter <=> group mappings
  $i = 0;

  // Check all contacts in CiviCRM  
  $civi_contacts = query_contacts($civi);
  while ($civi_contact = $civi_contacts->fetch_assoc()) {

    // For this contact, find their groups in civicrm_group_contact
    $query = 'SELECT * FROM civicrm_group_contact WHERE contact_id = ' .
      $civi_contact['id'];
    $civi_groups = $civi->query($query);
    if ($civi_groups === FALSE) {
      printf("Failed to'%s': %s\n", $query, $civi->error);
      exit(1); 
    }
    while ($civi_group = $civi_groups->fetch_assoc()) {
      //print_r($civi_group);
      $salsa_supporter_group = array();
      $salsa_supporter_group['supporter_KEY'] = $civi_contact['salsa_key'];
      $salsa_supporter_group['groups_KEY'] =
        $group_table[$civi_group['group_id']];
      // FIXME: any place to put civi status, location_id, email_id?
      $salsa_key = save_salsa($curl, 'supporter_groups',
			      $salsa_supporter_group, $civi_group);
      $i++;
    }
  }
  printf("Added %d supporter <=> group mappings to Salsa\n", $i);
}

/**
 *  Convert civicrm_event table to Salsa event table
 *
 *  Uses foreign keys:
 *    national_event_KEY
 *    distributed_event_KEY
 *    suppporter_KEY
 *    groups_KEYS
 *    required$groups_KEYS
 *    event$email_trigger_KEYS
 *    upgrade_$email_trigger_KEYS
 *    reminder_$email_trigger_KEYS
 *    supporter_picture_KEY
 *    supporter_my_donate_page_KEY
 *
 *  Provides key:
 *    event_KEY
 */
function cvt_event(mysqli $civi, $curl) {

  global $event_table, $loc_blk;

  // Add a salsa_key column to the civicrm_event table
  add_salsa_key($civi, 'civicrm_event');

  // Drop the salsa_key index
  drop_salsa_key_index($civi, 'civicrm_event');

  $civi_events = $civi->query("SELECT * FROM civicrm_event");
  if ($civi_events === FALSE) {
    printf("Failed to SELECT * FROM civicrm_event: %s\n",
      $civi->error);
    exit(1); 
  }
  while ($civi_event = $civi_events->fetch_assoc()) {
    $salsa_event = array();
    //$salsa_event['national_event_KEY'] = $civi_event['?'];
    //$salsa_event['distributed_event_KEY'] = $civi_event['?'];
    //$salsa_event['supporter_KEY'] = $civi_event['?'];
    //$salsa_event['Reference_Name'] = $civi_event['?'];
    $salsa_event['Event_Name'] = $civi_event['title'];
    // civicrm_event has a summary column with a brief description.
    // salsa event doesn't seem to have a place to put that.
    $salsa_event['Description'] = $civi_event['description'];
    $loc_blk_id = $civi_event['loc_block_id'];
    if (!empty($loc_blk_id)) {
      if (!empty($loc_blk[$loc_blk_id]['street'])) {
	$salsa_event['Address'] = $loc_blk[$loc_blk_id]['street'];
      }
      if (!empty($loc_blk[$loc_blk_id]['city'])) {
	$salsa_event['City'] = $loc_blk[$loc_blk_id]['city'];
      }
      if (!empty($loc_blk[$loc_blk_id]['state'])) {
	$salsa_event['State'] = $loc_blk[$loc_blk_id]['state'];
      }
      if (!empty($loc_blk[$loc_blk_id]['zip'])) {
	$salsa_event['Zip'] = $loc_blk[$loc_blk_id]['zip'];
      }
      if (!empty($loc_blk[$loc_blk_id]['country'])) {
	$salsa_event['Country'] = $loc_blk[$loc_blk_id]['country'];
      }
      if (!empty($loc_blk[$loc_blk_id]['latitude'])) {
        $salsa_event['Latitude'] = $loc_blk[$loc_blk_id]['latitude'];
      }
      if (!empty($loc_blk[$loc_blk_id]['longitude'])) {
        $salsa_event['Longitude'] = $loc_blk[$loc_blk_id]['longitude'];
      }
      if (!empty($loc_blk[$loc_blk_id]['email'])) {
        $salsa_event['Contact_Email'] = $loc_blk[$loc_blk_id]['email'];
      }
    }
    //$salsa_event['Directions'] = $civi_event[''];
    $salsa_event['Header'] = $civi_event['intro_text'];
    $salsa_event['Footer'] = $civi_event['footer_text'];
    //$salsa_event['PRIVATE_Zip_Plus_4'] = $civi_event[''];
    $salsa_event['Start'] = $civi_event['start_date'];
    $salsa_event['End'] = $civi_event['end_date'];
    //$salsa_event['Deadline'] = $civi_event[''];
    // civicrm_event doesn't have recurrence information
    $salsa_event['Recurrence_Frequency'] = 'None';
    //$salsa_event['Recurrence_Interval'] = $civi_event[''];
    //$salsa_event['No_Registration'] = $civi_event[''];
    //$salsa_event['Guests_allowed'] = $civi_event[''];
    $salsa_event['Maximum_Attendees'] = $civi_event['max_participants'];
    //$salsa_event['Maximum_Waiting_List_Size'] = $civi_event[''];
    //$salsa_event['Hide_Standard_Map'] = $civi_event[''];
    //$salsa_event['Map_URL'] = $civi_event[''];
    // FIXME Should be smarter but Save Our Canyon has no active events
    $salsa_event['Status'] ='Inactive';
    $salsa_event['This_Event_Costs_Money'] = $civi_event['is_monetary'];
    // FIXME Where is ticket price in civi??!!
    //$salsa_event['Ticket_Price'] = $civi_event[''];
    //$salsa_event['merchant_account_KEY'] = $civi_event[''];
    //$salsa_event['Default_Tracking_Code'] = $civi_event[''];
    //$salsa_event['redirect_path'] = $civi_event[''];
    //$salsa_event['Request'] = $civi_event[''];
    //$salsa_event['Required'] = $civi_event[''];
    //$salsa_event['groups_KEYS'] = $civi_event[''];
    //$salsa_event['required$groups_KEYS'] = $civi_event[''];
    //$salsa_event['Automatically_add_to_Groups'] = $civi_event[''];
    //$salsa_event['Display_to_Chapters'] = $civi_event[''];
    //$salsa_event['Request_Additional_Attendees'] = $civi_event[''];
    //$salsa_event['One_Column_Layout'] = $civi_event[''];
    //$salsa_event['Reminder_Status'] = $civi_event[''];
    //$salsa_event['Reminder_Hours'] = $civi_event[''];
    //$salsa_event['address_md5sum'] = $civi_event[''];
    //$salsa_event['Template'] = $civi_event[''];
    //$salsa_event['Allow_Guest_Signup'] = $civi_event[''];
    //$salsa_event['Maximum_Donation_Amount'] = $civi_event[''];
    $salsa_event['Confirmation_Text'] = $civi_event['confirm_text'];
    //$salsa_event['event$email_trigger_KEYS'] = $civi_event[''];
    //$salsa_event['waiting_list$email_trigger_KEYS'] = $civi_event[''];
    //$salsa_event['upgrade_$email_trigger_KEYS'] = $civi_event[''];
    //$salsa_event['reminder_$email_trigger_KEYS'] = $civi_event[''];
    //$salsa_event['supporter_picture_KEYS'] = $civi_event[''];
    //$salsa_event['supporter_my_donate_page_KEY'] = $civi_event[''];
    //$salsa_event['Location_Common_Name'] = $civi_event[''];
    //$salsa_event['Facebook_Connect'] = $civi_event[''];
    //$salsa_event['zoom_level'] = $civi_event[''];
    //$salsa_event['Invoice_Option'] = $civi_event[''];
    //$salsa_event['Facebook_ID'] = $civi_event[''];

    $salsa_key = save_salsa($curl, 'event', $salsa_event);
    $query = "UPDATE civicrm_event SET salsa_key = $salsa_key WHERE id = "
      . $civi_event['id']; 
    if (($result = $civi->query($query)) === FALSE) {
      printf("Failed to %s: %s\n", $query, $civi->error);
      exit(1);
    }
    $event_table[$civi_event['id']] = $salsa_key;
  }

  // Add an index on salsa_key
  add_salsa_key_index($civi, 'civicrm_event');
  printf("Added %d events to Salsa\n", count($event_table));
}

/**
 *  Convert CiviCRM civicrm_participant table to Salsa supporter_event table
 *
 *  Uses foreign keys:
 *    supporter_KEY
 *    event_KEY
 *    donation_KEY
 *    event_fee_KEY
 *
 *  Provides key:
 *    supporter_event_KEY
 *
 *  @todo Havilah uses roles as a proxy for comping admission to events,
 *    but CiviCRM still credits the comped person with donating a ticket.
 *    Figure out how to unwind this.
 */
function cvt_participant(mysqli $civi, $curl) {

  global $event_table;


  // Read the civicrm_participant_status_type table into memory
  $participant_status_types = array();
  $query = 'SELECT * FROM civicrm_participant_status_type';
  $civi_participant_status_types = $civi->query($query);
  if ($civi_participant_status_types === FALSE) {
    printf("Failed to '$query': %s\n", $civi->error);
    exit(1); 
  }
  while ($civi_participant_status_type =
    $civi_participant_status_types->fetch_assoc()) {
    if (!$civi_participant_status_type['is_active']) {
      continue;
    }
    $participant_status_types[$civi_participant_status_type['id']] =
      $civi_participant_status_type['label'];
  }
  //print_r($participant_status_types);

  // Read the possible participant roles
  // First we must find the option group for participant roles
  $query = "SELECT * FROM civicrm_option_group WHERE name = 'participant_role'";
  $civi_option_codes = $civi->query($query);
  if ($civi_option_codes === FALSE) {
    printf("Failed to %s: %s\n", $query, $civi->error);
    exit(1); 
  }
  $groups = array();
  while ($civi_option_code = $civi_option_codes->fetch_assoc()) {
    $groups[] = $civi_option_code['id'];
  }
  if (count($groups) != 1) {
    echo "Couldn't find unique option group id for participant_role\n";
    exit(1);
  }
  $participant_role_group = $groups[0];

  // Using the option group for participant roles we can find the
  // individual role codes and descriptions
  $query = "SELECT * FROM civicrm_option_value
     WHERE option_group_id = " . $participant_role_group;
  $civi_participant_roles = $civi->query($query);
  if ($civi_participant_roles === FALSE) {
    printf("Failed to '%s': %s\n", $query, $civi->error);
    exit(1); 
  }
  $participant_roles = array();
  while ($civi_participant_role = $civi_participant_roles->fetch_assoc()) {
    $participant_roles[$civi_participant_role['value']] =
      $civi_participant_role['label'];
  }
  //print_r($participant_roles);

  // Check all contacts
  $civi_contacts = query_contacts($civi);
  printf("Adding supporter_event rows to Salsa ...");
  $i = 0;
  while($civi_contact = $civi_contacts->fetch_assoc()) {
    // CiviCRM allows multiple rows in civicrm_participant with the
    // same contact_id and event_id.  Salsa does not allow multiple
    // rows in supporter_event with the same supporter_KEY and event_KEY
    $query = "SELECT * FROM civicrm_participant" .
      " WHERE contact_id=" . $civi_contact['id'];
    $civi_participants = $civi->query($query);
    if ($civi_participants === FALSE) {
      printf("Failed to '%s': %s\n", $query, $civi->error);
      exit(1); 
    }
    // For this contact, check all participation
    $at_event = array();
    while ($civi_participant = $civi_participants->fetch_assoc()) {
      if (array_key_exists($civi_participant['event_id'], $at_event)) {
        // We already stored a row showing this contact at this event.
        // If we try to store another it will fail because of a duplicate
        // key so just ignore this row.
        continue;
      }
      $at_event[$civi_participant['event_id']] = TRUE;
      $salsa_sup_evt = array();
      $salsa_sup_evt['supporter_KEY'] = $civi_contact['salsa_key'];
      $salsa_sup_evt['event_KEY'] = $event_table[$civi_participant['event_id']];
      //$salsa_sup_evt['donation_KEY'] = $civi_participant['?'];
      //$salsa_sup_evt['event_fee_KEY'] = $civi_participant['?'];
      //      switch ($participant_roles[$civi_participant['role_id']]) {
      //      case 'Attendee':
      $salsa_sup_evt['_Type'] = 'Supporter';
	//        break;
	//
	//      case 'Contractor':
	//      case 'Host':
	//      case 'Speaker':
	//      case 'Sponsor':
	//        $salsa_sup_evt['_Type'] = 'Organizer';
	//        break;
	//
	//      case 'Volunteer':
	//        $salsa_sup_evt['_Type'] = 'Volunteer';
	//        break;
	//
	//      default:
	//      }
      // FIXME: figure out how to show whether they actually attended
      if (empty($civi_participant['status_id'])) {
	echo "\nContact:\n"; print_r($civi_contact); echo "\n";
	echo "civi_participant:\n";  print_r($civi_participant); echo "\n";
      }
      switch ($participant_status_types[$civi_participant['status_id']]) {
      case 'Attended':
        $salsa_sup_evt['_Status'] = 'Attended';
        break;

      case 'Cancelled':
        $salsa_sup_evt['_Status'] = 'Cancelled';
        break;

      case 'Expired':
      case 'No-show':
        $salsa_sup_evt['_Status'] = 'Not attending';
        break;

      case 'Pending from incomplete transaction':
      case 'Pending from pay later':
        $salsa_sup_evt['_Status'] = 'Awaiting payment';
        break;

      case 'Registered':
        $salsa_sup_evt['_Status'] = 'Confirmed';
        break;

      default:
      }
      //$salsa_sup_evt['Additional_Attendees'] = $civi_participant['?'];
      //$salsa_sup_evt['Feedback'] = $civi_participant['?'];
      //print_r($salsa_sup_evt);
      $salsa_key = save_salsa($curl, 'supporter_event', $salsa_sup_evt);
    }
  }
  echo " done\n";
}

/**
 *  Convert civicrm_pledge table to Salsa recurring_donation table
 */
function cvt_pledge(mysqli $civi, $curl) {
}

/**
 *  Convert the $relationships table to households and supporter_household
 */
function cvt_relationships(mysqli $civi, $curl) {

  global $relationships;
  //print_r($relationships);

  $i = 0;

  // Check each household in the $relationships table
  foreach ($relationships as $rid => $relationship) {

    // Check whether this household has at least one member
    // with a Salsa supporter_KEY
    //print_r($relationship);
    $found_supporter = FALSE;
    foreach ($relationship['members'] as $contact_id => $supporter_KEY) {
      if ($supporter_KEY) {
        $found_supporter = TRUE;
        break;
      }
    }
    if (!$found_supporter) {
      continue;
    }

    // We found a household that can be added to Salsa.
    // We need to find a name and point of contact for this household.
    // We will use the lowest civicrm contact_id on the theory that
    // this is the initial contact.
    $low_contact_id = PHP_INT_MAX;
    foreach ($relationship['members'] as $contact_id => $supporter_KEY) {
      if (!empty($supporter_KEY) && ($contact_id < $low_contact_id)) {
        $low_contact_id = $contact_id;
        $point_KEY = $supporter_KEY;
      }
    }
    //echo "point contact_id =$low_contact_id supporter=$point_KEY\n";

    // We found the lowest contact id.  Use contact's last name for family
    $query = 'SELECT last_name FROM civicrm_contact WHERE id= ' .
      $low_contact_id;                
    $civi_point = $civi->query($query);
    if ($civi_point === FALSE) {
      printf("Failed to '%s': %s\n", $query, $civi->error);
      exit(1);
    }
    $point_name = $civi_point->fetch_assoc();
    $household = array(
      'Household_Name' => $point_name['last_name'],
      'supporter_KEY'  => $point_KEY,
    );
    //print_r($household);
    $household_KEY = save_salsa($curl, 'household', $household);
    //echo "household_KEY=$household_KEY\n";
    $relationships[$rid]['salsa_key'] = $household_KEY;

    // Add every member of this household that has a supporter_KEY
    //print_r($relationship['members']);
    foreach ($relationship['members'] as $contact_id => $supporter_KEY) {
      //echo "contact_id=$contact_id supporter_KEY=$supporter_KEY\n";
      if ($supporter_KEY) {
        $supporter_household = array(
          'household_KEY' => $household_KEY,
          'supporter_KEY' => $supporter_KEY,
        );
        //print_r($supporter_household);
        $supporter_household_KEY = save_salsa($curl, 'supporter_household',
          $supporter_household);
        //echo "supporter_household_KEY=$supporter_household_KEY\n";
      }
    }
  $i++;
  }
  printf("Added %d households to Salsa\n", $i);
}

/**
 * Delete objects from Salsa
 */
function delete_objects($curl, $object) {

  $query = SALSA_URL .
    '/api/getObjects.sjs?object=' . $object . '&include=' . $object . '_KEY';
  curl_setopt($curl, CURLOPT_URL, $query);
  $xml = curl_exec($curl);
  //echo "\nXML:\n"; print_r($xml); echo "\n";
  $objects = new SimpleXMLElement($xml);
  //echo "\nelement:\n"; print_r($objects); echo "\n";
  $n = count($objects->{$object}->item);
  printf("Deleting %d %s objects from Salsa...", $n, $object);
  if ($n == 0) {
    echo " done\n";
    return;
  }
  foreach ($objects->{$object}->item as $item) {
    //printf("Deleting $object key=%d\n", $item->key);
    $query = SALSA_URL . '/delete?xml&object=' . $object . '&key=' . $item->key;
    //printf("Query: %s\n", $query);
    curl_setopt($curl, CURLOPT_URL, $query);
    $xml = curl_exec($curl);
    $result = new SimpleXMLElement($xml);
    //print_r($result);
    //if (!$result->success) {
    //  echo "Failed to '$query'\n";
    //  exit(1);
    //}
  }
  echo " done\n";
}

/**
 *  Get contribution status codes
 */
function get_contribution_status(mysqli $civi) {

  global $contribution_status;

  // First we must find the option group for contribution status
  $query = "SELECT * FROM civicrm_option_group
    WHERE name = 'contribution_status'";
  $civi_option_codes = $civi->query($query);
  if ($civi_option_codes === FALSE) {
    printf("Failed to '%s': %s\n", $query, $civi->error);
    exit(1); 
  }
  $groups = array();
  while ($civi_option_code = $civi_option_codes->fetch_assoc()) {
    $groups[] = $civi_option_code['id'];
  }
  if (count($groups) != 1) {
    echo "Couldn't find unique option group id for contribution_status\n";
    exit(1);
  }
  $contribution_status_group = $groups[0];

  // Using the option group for contribution status we can find the
  // individual contribution status codes and descriptions
  $query = "SELECT * FROM civicrm_option_value
    WHERE option_group_id = $contribution_status_group";
  $civi_contribution_statuses = $civi->query($query);
  if ($civi_contribution_statuses === FALSE) {
    printf("Failed to '%s': %s\n", $query, $civi->error);
    exit(1); 
  }
  $contribution_status = array();
  while ($civi_contribution_status =
    $civi_contribution_statuses->fetch_assoc()) {
    $contribution_status[$civi_contribution_status['value']] = 
      $civi_contribution_status['label'];
  }
}


/**
 * Get CiviCRM county codes and names
 */
function get_county(mysqli $civi) {

  global $county;

  $civi_counties = $civi->query("SELECT * FROM civicrm_county");
  if ($civi_counties === FALSE) {
    printf("Failed to SELECT * FROM civicrm_county: %s\n",
      $civi->error);
    exit(1); 
  }
  $county = array();
  while ($civi_county = $civi_counties->fetch_assoc()) {
    $county[$civi_county['id']] = $civi_county['name'];
  }
}

/**
 * Get CiviCRM country codes and names
 */
function get_cntry(mysqli $civi) {

  global $cntry;

  $civi_countries = $civi->query("SELECT * FROM civicrm_country");
  if ($civi_countries === FALSE) {
    printf("Failed to SELECT * FROM civicrm_country: %s\n",
      $civi->error);
    exit(1); 
  }
  $cntry = array();
  while ($civi_country = $civi_countries->fetch_assoc()) {
    $cntry[$civi_country['id']] = $civi_country['name'];
  }
}

/**
 * Get CiviCRM event type codes and values
 */
function get_event_type(mysqli $civi) {

  global $event_type;

  // First we must find the option group for event types
  $civi_option_codes = $civi->query("SELECT * FROM civicrm_option_group
    WHERE name = 'event_type'");
  if ($civi_option_codes === FALSE) {
    printf("Failed to SELECT 'event_type' FROM"
      . " civicrm_option_group: %s\n", $civi->error);
    exit(1); 
  }
  $groups = array();
  while ($civi_option_code = $civi_option_codes->fetch_assoc()) {
    $groups[] = $civi_option_code['id'];
  }
  if (count($groups) != 1) {
    echo "Couldn't find unique option group id for event_type\n";
    exit(1);
  }
  $event_type_group = $groups[0];

  // Using the option group for event types we can find the
  // individual event type codes and descriptions
  $civi_event_types = $civi->query("SELECT * FROM civicrm_option_value
    WHERE option_group_id = $event_type_group");
  if ($civi_event_types === FALSE) {
    printf("Failed to SELECT * FROM civicrm_option_value WHERE"
      . " option_group_id = %d: %s\n", $event_type_group, $civi->error);
    exit(1); 
  }
  $event_type = array();
  while ($civi_event_type = $civi_event_types->fetch_assoc()) {
    $event_type[$civi_event_type['value']] = array(
      'name' => $civi_event_type['name'],
      'label' => $civi_event_type['label'],
      'description' => $civi_event_type['description'],
    );
  }
}

/**
 * Get CiviCRM honor type codes and values
 *
 * 'In Honor/Memory Of'
 */
function get_honor_type(mysqli $civi) {

  global $honor_type;

  // First we must find the option group for honor types
  $civi_option_codes = $civi->query("SELECT * FROM civicrm_option_group
    WHERE name = 'honor_type'");
  if ($civi_option_codes === FALSE) {
    printf("Failed to SELECT 'honor_type' FROM"
      . " civicrm_option_group: %s\n", $civi->error);
    exit(1); 
  }
  $groups = array();
  while ($civi_option_code = $civi_option_codes->fetch_assoc()) {
    $groups[] = $civi_option_code['id'];
  }
  if (count($groups) != 1) {
    echo "Couldn't find unique option group id for honor_type\n";
    exit(1);
  }
  $honor_type_group = $groups[0];

  // Using the option group for honor types we can find the
  // individual honor type codes and descriptions
  $civi_honor_types = $civi->query("SELECT * FROM civicrm_option_value
    WHERE option_group_id = $honor_type_group");
  if ($civi_honor_types === FALSE) {
    printf("Failed to SELECT * FROM civicrm_option_value WHERE"
      . " option_group_id = %d: %s\n", $honor_type_group, $civi->error);
    exit(1); 
  }
  $honor_type = array();
  while ($civi_honor_type = $civi_honor_types->fetch_assoc()) {
    $honor_type[$civi_honor_type['value']] = array(
      'label' => $civi_honor_type['label'],
    );
  }
}

/**
 *  Get CiviCRM location block codes and values
 *
 *  We ignore im_id and all the *_2_id columns in civicrm_loc_block because
 *  they are unused by Save Our Canyons.
 */
function get_loc_blk(mysqli $civi) {

  global $cntry, $loc_blk, $state;

  $civi_loc_blks = $civi->query("SELECT * FROM civicrm_loc_block");
  if ($civi_loc_blks === FALSE) {
    printf("Failed to SELECT * FROM civicrm_loc_block: %s\n",
      $civi->error);
    exit(1); 
  }
  $loc_blk = array();
  while ($civi_loc_blk = $civi_loc_blks->fetch_assoc()) {
    $addr_id = $civi_loc_blk['address_id'];
    if (empty($addr_id)) {
      continue;
    }
    $civi_loc_addr = $civi->query("SELECT * FROM civicrm_address
      WHERE id = $addr_id");
    if ($civi_loc_addr === FALSE) {
      printf("Failed to SELECT * FROM civicrm_address"
        . " WHERE id = $addr_id: %s\n",	$civi->error);
      exit(1); 
    }

    // There should be exactly one address per address ID
    $loc_addr = $civi_loc_addr->fetch_assoc();
    $loc_blk[$civi_loc_blk['id']]['street'] = $loc_addr['street_address'];
    $loc_blk[$civi_loc_blk['id']]['street2'] =
      $loc_addr['supplemental_address_1'];
    $loc_blk[$civi_loc_blk['id']]['street3'] =
      $loc_addr['supplemental_address_2'];
    $loc_blk[$civi_loc_blk['id']]['city'] = $loc_addr['city'];
    $loc_blk[$civi_loc_blk['id']]['zip'] = $loc_addr['postal_code'];
    $loc_blk[$civi_loc_blk['id']]['state']  =
      !empty($loc_addr['state_province_id']) 
      ? $state[$loc_addr['state_province_id']] : NULL;
    $loc_blk[$civi_loc_blk['id']]['country']  = !empty($loc_addr['country_id']) 
      ? $cntry[$loc_addr['country_id']] : NULL;
    $loc_blk[$civi_loc_blk['id']]['latitude']  = $loc_addr['geo_code_1'];
    $loc_blk[$civi_loc_blk['id']]['longitude']  = $loc_addr['geo_code_2'];
    $email_id = $civi_loc_blk['email_id'];
    if (!empty($email_id)) {
      // add the email address
      $civi_loc_email = $civi->query("SELECT * FROM civicrm_email
        WHERE id = $email_id");
      if ($civi_loc_email === FALSE) {
        printf("Failed to SELECT * FROM civicrm_email"
          . " WHERE id = $email_id: %s\n", $civi->error);
        exit(1); 
      }
      // There should be exactly one email per email ID
      $loc_email = $civi_loc_email->fetch_assoc();
      $loc_blk[$civi_loc_blk['id']]['email'] = $loc_email['email'];
    }
    $phone_id = $civi_loc_blk['phone_id'];
    if (!empty($phone_id)) {
      // add the phone number
      $civi_loc_phone = $civi->query("SELECT * FROM civicrm_phone
        WHERE id = $phone_id");
      if ($civi_loc_phone === FALSE) {
        printf("Failed to SELECT * FROM civicrm_phone"
          . " WHERE id = $phone_id: %s\n", $civi->error);
        exit(1); 
      }
      // There should be exactly one phone number per phone ID
      $loc_phone = $civi_loc_phone->fetch_assoc();
      $loc_blk[$civi_loc_blk['id']]['phone'] = $loc_phone['phone'];
    }
  }
}

/**
 * Get CiviCRM location types from civicrm_location_type table
 */
function get_loc_type(mysqli $civi) {

  global $loc_type;

  // Get location types from civicrm_location_type
  $civi_location_types = $civi->query("SELECT * FROM civicrm_location_type");
  if ($civi_location_types === FALSE) {
    printf("Failed to SELECT * FROM civicrm_location_type: %s\n",
      $civi->error);
    exit(1); 
  }
  $loc_type = array();
  while ($civi_location_type = $civi_location_types->fetch_assoc()) {
    $loc_type[$civi_location_type['id']] = $civi_location_type['name'];
  }
}

/**
 *  Get CiviCRM payment instruments
 *
 *  Check, credit card etc.
 */
function get_payment_instrument(mysqli $civi) {

  global $payment_instrument;

  // First we must find the option group for payment_instrument
  $civi_option_codes = $civi->query("SELECT * FROM civicrm_option_group
    WHERE name = 'payment_instrument'");
  if ($civi_option_codes === FALSE) {
    printf("Failed to SELECT 'payment_instrument' FROM"
      . " civicrm_option_group: %s\n", $civi->error);
    exit(1); 
  }
  $groups = array();
  while ($civi_option_code = $civi_option_codes->fetch_assoc()) {
    $groups[] = $civi_option_code['id'];
  }
  if (count($groups) != 1) {
    echo "Couldn't find unique option group id for payment_instrument\n";
    exit(1);
  }
  $payment_inst_group = $groups[0];

  // Using the option group for payment instrument we can find the
  // individual payment instrument codes and values
  $civi_payment_insts = $civi->query("SELECT * FROM civicrm_option_value
    WHERE option_group_id = $payment_inst_group");
  if ($civi_payment_insts === FALSE) {
    printf("Failed to SELECT * FROM civicrm_option_value WHERE"
      . " option_group_id = %d: %s\n", $payment_inst_group, $civi->error);
    exit(1); 
  }
  $payment_instrument = array();
  while ($civi_payment_inst = $civi_payment_insts->fetch_assoc()) {
    $payment_instrument[$civi_payment_inst['value']] =
      $civi_payment_inst['label'];
  }
}

/**
 * Get CiviCRM individual name prefix codes and values
 *
 * Name prefix is something like "Dr.", "Mrs." etc.
 */
function get_prefix(mysqli $civi) {

  global $prefix;

  // First we must find the option group for name prefixes
  $civi_option_codes = $civi->query("SELECT * FROM civicrm_option_group
    WHERE name = 'individual_prefix'");
  if ($civi_option_codes === FALSE) {
    printf("Failed to SELECT 'individual_prefix' FROM"
      . " civicrm_option_group: %s\n", $civi->error);
    exit(1); 
  }
  $groups = array();
  while ($civi_option_code = $civi_option_codes->fetch_assoc()) {
    $groups[] = $civi_option_code['id'];
  }
  if (count($groups) != 1) {
    echo "Couldn't find unique option group id for individual_prefix\n";
    exit(1);
  }
  $prefix_group = $groups[0];

  // Using the option group for name prefixes we can find the
  // individual name prefix codes and values
  $civi_prefixes = $civi->query("SELECT * FROM civicrm_option_value
    WHERE option_group_id = $prefix_group");
  if ($civi_prefixes === FALSE) {
    printf("Failed to SELECT * FROM civicrm_option_value WHERE"
      . " option_group_id = %d: %s\n", $prefix_group, $civi->error);
    exit(1); 
  }
  $prefix = array();
  while ($civi_prefix = $civi_prefixes->fetch_assoc()) {
    $prefix[$civi_prefix['value']] = $civi_prefix['name'];
  }
}

/**
 *  Convert civicrm_relation table to array of relationships
 *
 *  Read the pairs in the civicrm_relationship table and group contacts
 *  that have a domestic relationship into households with space to store
 *  a Salsa key.
 */
function get_relationships(mysqli $civi) {

  global $relationships, $relationship_type;

  // Find the CiviCRM relationship types that define a household
  $domestic = array();
  $pattern = '/^Child|^Family|^Sibling|^Significant|^Spouse/';
  foreach ($relationship_type as $id => $data) {
    if (preg_match($pattern, $data['name_a_b'])) {
	$domestic[] =$id;
      }
  }

  // Find the groups of contacts that are members of a household
  $query = 'SELECT contact_id_a, contact_id_b FROM civicrm_relationship' .
    ' WHERE relationship_type_id IN (' . implode(',', $domestic) . ')' .
    ' AND is_active=1';
  //echo "Query: $query\n";
  $civi_relationships = $civi->query($query);
  if ($civi_relationships === FALSE) {
    printf("Failed to '%s': %s\n", $query, $civi->error);
    exit(1);      
  }

  $relationships = array();
  while ($civi_relationship = $civi_relationships->fetch_assoc()) {
    //echo "civi_relationship: "; print_r($civi_relationship); echo "\n";
    //echo "relationships: "; print_r($relationships); echo "\n";
    foreach($relationships as $key => $household) {
      //echo "household: "; print_r($household); echo "\n";
      if (array_key_exists($civi_relationship['contact_id_a'],
        $relationships[$key]['members'])) {
        $relationships[$key]['members'][$civi_relationship['contact_id_b']] =
          FALSE;
        //echo "added " . $civi_relationship['contact_id_b'] . " to household\n";
        //echo "household: "; print_r($relationships[$key]); echo "\n";
        continue 2;
      }
      elseif (array_key_exists($civi_relationship['contact_id_b'],
        $relationships[$key]['members'])) {
        $relationships[$key]['members'][$civi_relationship['contact_id_a']] =
          FALSE;
        //echo "added " . $civi_relationship['contact_id_a'] . " to household\n";
        //echo "household: "; print_r($relationships[$key]); echo "\n";
        continue 2;
      }
    }    
    // No household has either of these contacts yet,
    // so make a new household to contain them.
    $relationships[] = array(
      'salsa_key' => FALSE,
      'members'   => array(
        $civi_relationship['contact_id_a'] => FALSE,
        $civi_relationship['contact_id_b'] => FALSE,
      )
    );
    //echo "created new household\n";
  }
  printf("Found %d domestic relationships in CiviCRM\n", count($relationships));
}

/**
 * Get CiviCRM relationship types from civicrm_relationship_type table
 */
function get_relationship_type(mysqli $civi) {

  global $relationship_type;

  $query = "SELECT * FROM civicrm_relationship_type";
  $civi_relationship_types = $civi->query($query);
  if ($civi_relationship_types === FALSE) {
    printf("Failed to '%s': %s\n", $query, $civi->error);
    exit(1); 
  }
  $relationship_type = array();
  while ($civi_relationship_type = $civi_relationship_types->fetch_assoc()) {
    $relationship_type[$civi_relationship_type['id']] = $civi_relationship_type;
  }
  //print_r($relationship_type);
}

/**
 *  Get CiviCRM state/province codes and names from civicrm_state_province
 *
 *  Store the contents of civicrm_state_province in $state
 */
function get_state(mysqli $civi) {

  global $state;

  $civi_states = $civi->query("SELECT * FROM civicrm_state_province");
  if ($civi_states === FALSE) {
    printf("Failed to SELECT * FROM civicrm_state_province: %s\n",
      $civi->error);
    exit(1); 
  }
  $state = array();
  while ($civi_state = $civi_states->fetch_assoc()) {
    $state[$civi_state['id']] = $civi_state['abbreviation'];
  }
}

/**
 *  Test whether a row with a given id exists in a given table
 */
function is_row_present(mysqli $civi, $table, $id) {
  $query = 'SELECT COUNT(*) FROM ' . $table . '  WHERE id=' . $id;
  //printf("Query: '%s'\n", $query);
  $row_exist = $civi->query($query);
  if ($row_exist === FALSE) {
    printf("Failed to'%s': %s\n", $query, $civi->error);
    exit(1); 
  }
  $row_count = $row_exist->fetch_assoc();
  if ($row_count['COUNT(*)'] == 1) {
    return TRUE;
  }
  return FALSE;
}

/**
 *  Query civicrm_contact
 *
 *  Return all or selected rows depending on contents of $contact_ids
 *
 *  @return resource Result of query for contacts
 */
function query_contacts(mysqli $civi) {

  global $contact_ids;

  $query = 'SELECT * FROM civicrm_contact';
  if (!empty($contact_ids)) {
    //printf("%d numbers in \$contact_ids\n", count($contact_ids));
    $query .= ' WHERE id IN (' . implode(',', $contact_ids) . ')';
  }
  //printf("Query: '%s'\n", $query);
  $civi_contacts = $civi->query($query);
  if ($civi_contacts === FALSE) {
    printf("Failed to '$query': %s\n", $civi->error);
    exit(1); 
  }
  //printf("%d rows returned by query\n", $civi_contacts->num_rows);
  return $civi_contacts;
}

/**
 *  Query one unique row from the CiviCRM DB.
 *
 *  If row can't be found return FALSE.
 *  If more than one row matches, output an error message and terminate.
 */
function query_unique(mysqli $civi, $query) {
  $result = $civi->query($query);
  if ($result === FALSE) {
    printf("Failed to '$query': %s\n", $civi->error);
    exit(1); 
  }
  if ($result->num_rows == 0) {
    return FALSE;
  }
  if ($result->num_rows > 1) {
    printf("query_unique() query %s return %d rows\n",
      $query, $result->num_rows);
    exit(1);
  }
  $row = $result->fetch_assoc();
  return $row;
}

/**
 *  Save a Salsa object using cURL
 *
 *  @return key generated by Salsa save
 */
function save_salsa($curl, $obj_name, array $obj_val, $civi_row = array()) {

    $url = SALSA_URL . '/save?xml&object='. $obj_name . '&' . 
      http_build_query($obj_val, '', '&');
    if ($obj_name == 'soft_credit') {
      printf("URL: %s\n", $url);
    }
    curl_setopt_array($curl,
      array(
	CURLOPT_URL        => $url,
	CURLOPT_HTTPGET    => TRUE,
	)
      );
    $xml = curl_exec($curl);
    $pattern = '/<\?xml.*$/s';
    $matches = array();
    $result = preg_match($pattern, $xml, $matches);
    if ($result != 1) {
      echo "oops, expected XML not found!\n";
    }
    else {
      $cleaned_xml = $matches[0];
    }
    $response = new SimpleXMLElement($cleaned_xml);
    if ($response->error) {    
      printf("Failed to store object %s: %s",
      $obj_name, $response->error);
      echo "\nURL: $url\n";
      echo "\nXML:\n"; print_r($xml);
      echo "\nObject is:\n";  print_r($obj_val);
      echo "\n";
      if (!empty($civi_row)) {
        echo "CiviCRM row is:\n";
        print_r($civi_row);
        echo "\n";
      }

      exit(1);
    }
    $response_ary = (array)$response->success->attributes();
    $key = $response_ary['@attributes']['key']; 
    if (empty($key)) {
      echo "\nNo key returned from Salsa save.  XML:\n";
      print_r($xml);
      echo "\n";
      exit(1);     
    }
    return $key;
}