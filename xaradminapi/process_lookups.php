<?php
/**
 * Reminders Module
 *
 * @package modules
 * @subpackage reminders
 * @category Third Party Xaraya Module
 * @version 1.0.0
 * @copyright (C) 2019 Luetolf-Carroll GmbH
 * @license GPL {@link http://www.gnu.org/licenses/gpl.html}
 * @author Marc Lutolf <marc@luetolf-carroll.com>
 */


function reminders_adminapi_process_lookups($args)
{
    if (!isset($args['test']))        $args['test'] = false;
    if (!isset($args['params']))      $args['params'] = array();
    if (!isset($args['copy_emails'])) $args['copy_emails'] = false;

    sys::import('modules.dynamicdata.class.objects.master');
    $mailer_template = DataObjectMaster::getObject(array('name' => 'mailer_mails'));
    
    // Get the lookups we want an email for
    if ($args['test']) {
    	// In the test environment, we just look which lookups were checked in the test lookup admin page. We will receive all those emails.
    	if (!xarVarFetch('entry_list',    'str', $data['entry_list'],    '', XARVAR_NOT_REQUIRED)) return;
    	$state = 0;
    } else {
    	// In the live invironment we get exactly one lookup, which will correspond to a single email we receive
    	$id = xarMod::apiFunc('reminders', 'admin', 'generate_renadom_entry('user', xarUser::getVar('id'));
    	$data['entry_list'] = $id;
    }
    
	$items = xarMod::apiFunc('reminders', 'user', 'getall_lookups', array('itemids' => $data['entry_list'], 'state' => $state));

    // Get today's date
    $datetime = new XarDateTime();
    $datetime->settoday();
    $today = $datetime->getTimestamp();

    // Run through the active reminders and send emails
    $current_id = 0;
    $previous_id = 0;
    $templates = array();
    $data['results'] = array();
        
    // Create a query object for reuse throughout
    sys::import('xaraya.structures.query');
    $tables = xarDB::getTables();
    $q = new Query('UPDATE', $tables['reminders_lookups']);    
    
    /*
    * For each item we need to find the latest reminder that has not yet been sent
    */
    
    $data['results'] = array();
    foreach ($items as $key => $row) {
    
    	// Prepare the data we need to send an email
		// Get the template information for this message
		$this_template_id = $row['template_id'];
		if (isset($templates[$this_template_id])) {
			// We already have the information.
		} else {
			// Get the information
			$mailer_template->getItem(array('itemid' => $this_template_id));
			$values = $mailer_template->getFieldValues();
			$templates[$this_template_id]['message_id']   = $values['id'];
			$templates[$this_template_id]['message_body'] = $values['body'];
			$templates[$this_template_id]['subject']      = $values['subject'];
		}
		// Assemble the parameters for the email
		$params['message_id']   = $templates[$this_template_id]['message_id'];
		$params['message_body'] = $templates[$this_template_id]['message_body'];
		$params['subject']      = $templates[$this_template_id]['subject'];
 	
    	// If this is a test, just send the mail
		if ($args['test']) {
			// Send the email
			$data['result'] = xarMod::apiFunc('reminders', 'admin', 'send_email_lookup', array('info' => $row, 'params' => $params, 'copy_emails' => $args['copy_emails'], 'test' => $args['test']));        	
			$data['results'] = array_merge($data['results'], array($data['result']));

			// We are done with this reminder
			break;
		}

    	// If we are past the due date, then make this reminder inactive and spawn a new one if need be
    	if ($row['due_date'] < $today) {

			// Retire the reminder
			xarMod::apiFunc('reminders', 'admin', 'retire', array('itemid' => $row['id'], 'recurring' => $row['recurring']));
	    	
			// We are done with this reminder
			break;
    	}    	
    }
    return $data['results'];
}
?>