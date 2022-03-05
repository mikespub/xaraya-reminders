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
    
    // Get the lookup entries to process
    if ($args['test']) {
    	if (!xarVarFetch('entry_list',    'str', $data['entry_list'],    '', XARVAR_NOT_REQUIRED)) return;
    	$state = 0;
    } else {
    	$data['entry_list'] = '';
    	$state = 3;
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

    	// If today is the due date, send the email in any case
    	if ($row['due_date'] == $today) {
			// Send the email
			$data['result'] = xarMod::apiFunc('reminders', 'admin', 'send_email_lookup', array('info' => $row, 'params' => $params, 'copy_emails' => $args['copy_emails'], 'test' => $args['test']));        	
			$data['results'] = array_merge($data['results'], array($data['result']));

			// Retire the reminder
			xarMod::apiFunc('reminders', 'admin', 'retire', array('itemid' => $row['id'], 'recurring' => $row['recurring']));
	    	
			// We are done with this reminder
			break;
    	}
    	
    /*
    * At this point the due date is still in the future
    * We need to go through the dates and find the correct one to send
    */
    	// Get the array of all the reminder dates of this reminder
    	$dates = xarMod::apiFunc('reminders', 'user', 'get_date_array', array('array' => $row));
    	
    	// Run through each of the 10 possible steps
    	$done = false;
    	$sent_ids = array();
    	foreach ($dates as $step) {
    	
    		// An empty step means that no date was defined
    		if ($step['date'] == 0) continue;
    		
    		// We ignore steps with dates that have already passed, whether or not an email was sent
    		if ($step['date'] < $today) continue;
    		
    		// If the step date coincides with today's date and an email has not been sent, we send an email
    		if (($step['date'] == $today) && ($step['done'] == 0)) {
				// Send the email
				$data['result'] = xarMod::apiFunc('reminders', 'admin', 'send_email_lookup', array('info' => $row, 'params' => $params, 'copy_emails' => $args['copy_emails'], 'test' => $args['test']));        	
				$data['results'] = array_merge($data['results'], array($data['result']));
               
    			// This is not a test, so set this period reminder as done
    			if (!$args['test']) {
	    			$sent_ids[] = $step['index'];
	    			$done = true;
	    			// Jump to the next iteration
	    			continue;
    			}
    		}
    			
    		// Run through the rest of the steps, in case we have 2 or more with today's date
    		if (($step['date'] == $today) && ($step['done'] == 0)) {
	    		$sent_ids[] = $step['index'];
    		}
    	}
    	
    	// Update this reminder for the email(s) we have sent
    	if (!empty($sent_ids)) {
			$q->clearfields();
			$q->clearconditions();
			$q->eq('id', (int)$row['id']);
			foreach ($sent_ids as $id) {
				$q->addfield('reminder_done_' . $id, 1);
			}
			$q->run();
    	}
    	
    }
    return $data['results'];
}
?>