<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * Restore multiple courses to their original category names.
 * based on idea at https://moodle.org/mod/forum/discuss.php?d=196596#p861663
 * drop in the /admin/cli/ folder and execute via cli
 *
 * @package    cli
 * @copyright  2020 Tim St.Clair (https://github.com/frumbert/)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 *
 */

define('CLI_SCRIPT', true);

require(dirname(dirname(dirname(__FILE__))).'/config.php');
require_once($CFG->libdir.'/clilib.php');      // cli only functions
require_once($CFG->libdir.'/cronlib.php');
require_once($CFG->dirroot . '/backup/util/includes/restore_includes.php');

// Now get cli options.
list($options, $unrecognized) = cli_get_params(array(
    'help' => false,
    'path' => '',
    'remove' => false
),
array(
    'h' => 'help',
    'p' => 'path',
    'r' => 'remove'
));

if ($unrecognized) {
    $unrecognized = implode("\n  ", $unrecognized);
    cli_error(get_string('cliunknowoption', 'admin', $unrecognized));
}

$help =
"Restore all courses to their categories.

Options:
-h, --help                 Print out this help
-p, --path                 full path to source folder containing moodle backups
-r, --remove               Remove source backups after a sucessful restore

Example:
\$sudo -u www-data /usr/bin/php admin/tool/restoreall/cli/restoreall.php
--path=/var/www/html/mysite/moodledata/backups
";

if ($options['help'] || empty($options['path'])) {
    echo $help;
    die();
}

$starttime = microtime();
$backupspath = $options['path'];
$remove = isset($options['remove']);

mtrace("Restoring all courses from {$backupspath}");

/// emulate normal session
cron_setup_user();

/// Start output log
$timenow = time();

mtrace("Server Time: ".date('r',$timenow)."\n\n");

//Start the restore process for the mbz files
require_once($CFG->dirroot . '/backup/util/includes/restore_includes.php');

//Read in all the mbz files in the source path
$MBZ = []; 
if ($handle = opendir($backupspath)) {
    while (false !== ($entry = readdir($handle))) {
        $file_info = pathinfo($backupspath.'/'.$entry);
		if(strtolower($file_info['extension']) == "mbz") {
			$MBZ[] = $entry;
		}
    }
    closedir($handle);
}

mtrace("Found " . count($MBZ) . " moodle backups.");

for($i=0; $i<count($MBZ); $i++) {

	// make some unique folder name
	$rand = md5(time() . $i);
	check_dir_exists($CFG->dataroot . '/temp/backup');
	if (extract_backup($backupspath . '/' . $MBZ[$i], $CFG->dataroot . '/temp/backup/' . $rand)) {

		// determine properties of course inside the backup
		if (file_exists($CFG->dataroot . '/temp/backup/' . $rand . '/course/course.xml')) {

			$xml = simplexml_load_file($CFG->dataroot . '/temp/backup/' . $rand . '/course/course.xml');

			if (isset($xml['id']) && intval($xml['id']) === 1) {
				mtrace("Skipping backup (cannot restore site)");
				continue;
			}

			$shortname = strval($xml->shortname);
			$fullname = strval($xml->fullname);
			$categoryname = strval($xml->category->name);

			if (empty($categoryname)) {
				$categoryid = \core_course_category::get_default();
				mtrace("Category not found in backup, using default category.");
			} else {

				// find the category by its name, and if that fails create the category
				$categoryid = $DB->get_field('course_categories', 'id', array('name'=>$categoryname));
				if (!$categoryid) {
					$categoryid = $DB->insert_record('course_categories', (object)array(
						'name' => $categoryname,
						'parent' => 0,
						'visible' => 1
					));
					$DB->set_field('course_categories', 'path', '/' . $categoryid, array('id'=>$categoryid));
					mtrace("Created new category {$categoryname} ({$categoryid})");
				}
			}

			// Create new empty course in the category
			$courseid = restore_dbops::create_new_course($fullname, $shortname, $categoryid);

			// restore the backup into this course
			if (restore_into_course($rand, $courseid)) {
			    if ($remove === true) {
			    	if (unlink($CFG->dataroot . '/temp/backup/' . $folder)) {
						mtrace("Deleted " . $backupspath . '/' . $MBZ[$i]);
					}
			    }
			};

		} else {
			mtrace('Skipped: Failed to open course.xml');
		}
	} else {
		mtrace('Skipped: Unable to extract ' . $MBZ[$i]);
	}

	// remove the source backup

	// ensure clean variables on the next iteration
	unset($xml,$shortname,$fullname,$categoryname,$categoryid,$courseid,$rand);
}

$difftime = microtime_diff($starttime, microtime());
mtrace("\n\nServer Time: ".date('r',$timenow)."\n");
mtrace("Execution took ".$difftime." seconds");

/* -------------------------------------- functions ------------------------------------------- */

function restore_into_course($folder, $courseid) {

    global $DB;
    $admin = get_admin();

    try {
	    $transaction = $DB->start_delegated_transaction();

	    $controller = new \restore_controller(
	        $folder, // name of extracted folder, assumbed to exist inside dirroot / temp / backup
	        $courseid,
	        \backup::INTERACTIVE_NO,
	        \backup::MODE_SAMESITE,
	        $admin->id,
	        \backup::TARGET_NEW_COURSE
	    );

	    $controller->execute_precheck(true);

		mtrace("Restoring {$folder} to course {$courseid}");
	    $controller->execute_plan();
	    $controller->destroy();

	    $transaction->allow_commit();

	    return true;

	} catch (Exception $e) {
		mtrace("Failed: " . $e->getMessage());
	}
	return false;
}

function extract_backup($backup, $dest) {

	mtrace("Extracting: {$backup} to {$dest}");

	check_dir_exists($dest,true,true);
    $fb = get_file_packer('application/vnd.moodle.backup');
    return $fb->extract_to_pathname($backup, $dest);
}

?>