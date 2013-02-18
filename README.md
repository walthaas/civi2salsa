Civi2Salsa
==========

WHAT IS THIS?
-------------

This is a program written for Save Our Canyons
(http://saveourcanyons.org) to simplify their migration from CiviCRM
3.3.5 (http://civicrm.org) to Salsa (http://www.salsalabs.com) in
early 2013.  This program is designed to convert and move data in ONE
direction only from ONE release of CiviCRM to whatever Salsa was
running at the time.  The program logic incorporates the practices of
Save Our Canyons in various ways, so it may not correctly convert from
CiviCRM used differently.

CAN I USE THIS PROGRAM?
-----------------------

You are free to use this program under the terms of the GPLv2 license
(see file LICENSE.txt).  As a practical matter, however, there are a
number of issues you must deal with.

1.  *Where is your CiviCRM database?*  This program assumes the database
    is in MySQL running on the same server.  If this is not true in your
    case, you may need to alter the code that connects to the CiviCRM
    database.

2.  *Can you access the CiviCRM database using an account that permits
    altering a table to add a column and an index?*  This program keeps
    track of where converted data is stored in Salsa by adding a column
    named salsa_key to certain tables in the CiviCRM database, which
    requires the Alter Table privilege in MySQL.

3.  *What release of CiviCRM is your database?*  This can be difficult to
    tell by examining the database.  Look in CiviCRM file
    civicrm-version.txt.  If it is not 3.3.5, you may need to modify this
    program. 

4.  *Will this program still work with Salsa?*  There is no effort to
    maintain this program as Salsa evolves.  YMMV.

5.  *How does your CiviCRM site use households and relationships?*  Save
    Our Canyons followed the practice of representing households in
    CiviCRM by spouse relationships instead of household relationships, so
    this program deals only with that situation.  If your CiviCRM database
    uses households as intended, you may need to modify this program.

6.  *The civicrm_activity table is not converted.* 

7.  *This program does not convert the parent/child relationships among
    CiviCRM groups.*

8.  *More than two email addresses for a contact will not be converted.*
    An error message will be emitted for any contact with more than two
    unique emails.

9.  *Contact name suffix is not converted.*

10. *When converting recurring contributions, only the month frequency
    unit and the Pending status is converted.*

11. *When reading location blocks from the civicrm_loc_block table, the
    im_id column and all the *_2_id columns are ignored.*

I'M WILLING TO TRY.  WHERE DO I START?
--------------------------------------

1.  You will almost certainly need to modify and test this program, so
    start by applying for a Salsa developer account here:

    http://www.salsalabs.com/devs/developer_signup

2.  Using the information from your developer account and the server
    running CiviCRM, copy the default.params.inc file in this directory to
    params.inc.  Then edit params.inc to describe where your data will
    come from and go to.  Start by converting into your developer sandbox,
    so SALSA_URL will be https://sandbox.salsalabs.com.  Define a list of
    contacts for testing in $contact_ids.

3.  When you are ready to do the final conversion, set $contact_ids
    empty and change SALSA_URL to the real server.  The URL depends on
    which node hosts the account, but might look something like
    https://hq-salsa3.salsalabs.com.  When you log in to the real Salsa
    account, the location bar of your browser will tell how to access your
    server.

 

