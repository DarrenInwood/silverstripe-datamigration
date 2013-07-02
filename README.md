silverstripe-datamigration
==========================

SilverStripe module to enable exporting DataObjects and files from one site, and importing into another

## Overview

This module provides some developer-only tools for exporting and re-importing DataObjects, including attached Files, from one system to another.
It is designed for when your client 'forgets' to reproduce their live changes on a staging server, and you need to merge content from two sites where you have different DataObjects on each site sharing the same IDs, retaining relationships between things, and also copying any files that need to be copied.

## Requirements

This is written for SilverStripe 2.4.x, and will not work with 3.x series due to differences in the datamodel.

## Installation

Download, unzip to your site root, and run /dev/build?flush=1.

To enable the data migration tool, add the following to your project's _config.php:

> DataMigration::enable();

This will need to be done on both sites.  Don't leave this turned on for longer than you need to on your live site!  It's a good idea to surround it with a conditional limiting it to just your machine somehow, eg:

> if ( $_SERVER['HTTP_HOST'] == '12.34.56' ) DataMigration::enable();

## Usage

You will need to do some manual research as to what has changed between sites using SQL.  The most useful query is probably:

> (SELECT ID, ClassName, Created FROM SiteTree) UNION (SELECT ID, ClassName, Created FROM SiteTree_Live) ORDER BY Created DESC LIMIT 100;

After determining the point at which the sites have diverged, we can create an export file from the staging site that will include all the data for the pages and attached DataObjects and files, and then import this into the live site.

### Always back up!!!

ALWAYS back up your assets folder and database from your live site before doing the import.

I've read this on a lot of pieces of software and often think "yeah I'll be fine" and don't back up.  This is NOT one of those modules!  It will probably fail the first time and you will probably have to reset your assets and database!!

### Create the data file

To create the data file from your staging server, visit:

> http://staging-server.com/datamigration/

Now copy and paste in the relevant lines from your SQL query's output.  Eg:

<pre>
+------+-----------------+
| ID   | ClassName       |
+------+-----------------+
| 2218 | NowShowingEvent |
| 2217 | NowShowingEvent |
| 2216 | NowShowingEvent |
| 2207 | NowShowingEvent |
| 2206 | NowShowingEvent |
| 2205 | NewsPage        |
| 2204 | NewsPage        |
| 2202 | NewsPage        |
| 2197 | NewsPage        |
| 2196 | NowShowingEvent |
+------+-----------------+
10 rows in set (0.00 sec)
</pre>

Submitting this will generate a download gzipped TAR file, containing a datafile (data.out) and any files attached to any objects.

### Importing the data file

If you have introduced any new classes on your staging server, they will of course need to be installed on your live server to transfer the data across.

It's a good idea to run an extra /dev/build?flush=1 on your live server just to make extra sure that the classnames are all in the manifest - especially if you've just restored live to an older backup!

Now, on the live server, visit:

> http://live-server.com/datamigration/import

Select your data file and submit.

This will give a readout of exactly what objects have been created, what existing objects have been reused in relations, what database values have been set, what relations have been made between files.

## Known issues

There are sadly still a lot of ways this can mess up!

It doesn't transfer the version history for items, or preserve the current published/unpublished status correctly.

It also seems to have a bug where it sometimes tries to use directories that don't exist - restore your assets folder and database to your backups, run /dev/build, create the directory it was looking for (with write permissions), and try again.
