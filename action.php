<?php

/**
 * ToDo Action Plugin: Inserts button for ToDo plugin into toolbar
 *
 * Original Example: http://www.dokuwiki.org/devel:action_plugins
 * @author     Babbage <babbage@digitalbrink.com>
 * @date 20130405 Leo Eibler <dokuwiki@sprossenwanne.at> \n
 *                replace old sack() method with new jQuery method and use post instead of get \n
 * @date 20130408 Leo Eibler <dokuwiki@sprossenwanne.at> \n
 *                remove getInfo() call because it's done by plugin.info.txt (since dokuwiki 2009-12-25 Lemming)
 */

if(!defined('DOKU_INC')) die();
/**
 * Class action_plugin_todo registers actions
 */
class action_plugin_todo extends DokuWiki_Action_Plugin {

    /**
     * Register the eventhandlers
     */
    public function register(&$controller) {
        $controller->register_hook('TOOLBAR_DEFINE', 'AFTER', $this, 'insert_button', array());
        $controller->register_hook('AJAX_CALL_UNKNOWN', 'BEFORE', $this, '_ajax_call', array());
    }

    /**
     * Inserts the toolbar button
     */
    public function insert_button(&$event, $param) {
        $event->data[] = array(
            'type' => 'format',
            'title' => $this->getLang('qb_todobutton'),
            'icon' => '../../plugins/todo/todo.png',
            'key' => 't',
            'open' => '<todo>',
            'close' => '</todo>',
            'block' => false,
        );
    }

    /**
     * Handles ajax requests for to do plugin
     *
     * @brief This method is called by ajax if the user clicks on the to-do checkbox or the to-do text.
     * It sets the to-do state to completed or reset it to open.
     *
     * POST Parameters:
     *   index    int the position of the occurrence of the input element (starting with 0 for first element/to-do)
     *   checked    int should the to-do set to completed (1) or to open (0)
     *   path    string id/path/name of the page
     *
     * @date 20130405 Leo Eibler <dokuwiki@sprossenwanne.at> \n
     *                replace old sack() method with new jQuery method and use post instead of get \n
     * @date 20130407 Leo Eibler <dokuwiki@sprossenwanne.at> \n
     *                add user assignment for todos \n
     * @date 20130408 Christian Marg <marg@rz.tu-clausthal.de> \n
     *                change only the clicked to-do item instead of all items with the same text \n
     *                origVal is not used anymore, we use the index (occurrence) of input element \n
     * @date 20130408 Leo Eibler <dokuwiki@sprossenwanne.at> \n
     *                migrate changes made by Christian Marg to current version of plugin \n
     *
     *
     * @param Doku_Event $event
     * @param mixed $param not defined
     */
    public function _ajax_call(&$event, $param) {
        global $ID;

        if($event->data !== 'plugin_todo') {
            return;
        }
        //no other ajax call handlers needed
        $event->stopPropagation();
        $event->preventDefault();

        #Variables
        // by einhirn <marg@rz.tu-clausthal.de> determine checkbox index by using class 'todocheckbox'

        if(isset($_REQUEST['index'], $_REQUEST['checked'], $_REQUEST['path'])) {
            // index = position of occurrence of <input> element (starting with 0 for first element)
            $index = urldecode($_REQUEST['index']);
            // checked = flag if input is checked means to do is complete (1) or not (0)
            $checked = urldecode($_REQUEST['checked']);
            // path = page ID (name)
            $ID = cleanID(urldecode($_REQUEST['path']));
        } else {
            return;
        }
        $origVal = '';
        if(isset($_REQUEST['origVal'])) {
            // origVal = urlencoded original value (in the case this is called by dokuwiki searchpattern plugin rendered page)
            $origVal = urldecode($_REQUEST['origVal']);
        }

        $INFO = pageinfo(); //FIXME is this same as global $INFO;?
        $fileName = $INFO['filepath'];

        #Determine Permissions
        if(auth_quickaclcheck($ID) < AUTH_EDIT) {
            echo "You do not have permission to edit this file.\nAccess was denied.";
            return;
        }

        #Retrieve File Contents
        $newContents = file_get_contents($fileName);

        $contentChanged = false;
        #Modify Contents

        if($index >= 0) {
            $index++;
            // no origVal so we count all todos with the method from Christian Marg
            // this will happen if we are on the current page with the todos
            $todoPos = strnpos($newContents, '<todo', $index);
            $todoTextPost = strpos($newContents, '>', $todoPos) + 1;
            if($todoTextPost > $todoPos) {
                $todoTag = substr($newContents, $todoPos, $todoTextPost - $todoPos);
                $newTag = $this->_todoProcessTag($todoTag, $checked);
                $newContents = substr_replace($newContents, $newTag, $todoPos, ($todoTextPost - $todoPos));
                $contentChanged = true;
            }
        } else {
            // this will happen if we are on a dokuwiki searchpattern plugin summary page
            if($checked) {
                $pattern = '/(<todo[^#>]*>(' . $this->_todoStr2regex($origVal) . '<\/todo[\W]*?>))/';
            } else {
                $pattern = '/(<todo[^#>]*#[^>]*>(' . $this->_todoStr2regex($origVal) . '<\/todo[\W]*?>))/';
            }
            $x = preg_match_all($pattern, $newContents, $spMatches, PREG_OFFSET_CAPTURE);
            if($x && isset($spMatches[0][0])) {
                // yes, we found matches and index is in a valid range
                $todoPos = $spMatches[1][0][1];
                $todoTextPost = $spMatches[2][0][1];
                $todoTag = substr($newContents, $todoPos, $todoTextPost - $todoPos);
                $newTag = $this->_todoProcessTag($todoTag, $checked);
                $newContents = substr_replace($newContents, $newTag, $todoPos, ($todoTextPost - $todoPos));
                $contentChanged = true;
            }
        }

        if($contentChanged) {
            #Save Update (Minor)
            io_writeWikiPage($fileName, $newContents, $ID, '');
            addLogEntry(saveOldRevision($ID), $ID, DOKU_CHANGE_TYPE_MINOR_EDIT, "Checkbox Change", '');
        }

    }

#(Possible) Alternative Method
//Retrieve mtime from file
//Load Data
//Modify Data
//Save Data
//Replace new mtime with previous one

    /**
     * @brief gets current to-do tag and returns a new one depending on checked
     * @param $todoTag    string current to-do tag e.g. <todo @user>
     * @param $checked    int check flag (todo completed=1, todo uncompleted=0)
     * @return string new to-do completed or uncompleted tag e.g. <todo @user #>
     */
    private function _todoProcessTag($todoTag, $checked) {
        $x = preg_match('%<todo([^>]*)>%i', $todoTag, $pregmatches);
        $newTag = '<todo';
        if($x) {
            if(($uPos = strpos($pregmatches[1], '@')) !== false) {
                $match2 = substr($todoTag, $uPos);
                $x = preg_match('%@([-.\w]+)%i', $match2, $pregmatches);
                if($x) {
                    $todo_user = $pregmatches[1];
                    $newTag .= ' @' . $todo_user;
                }
            }
        }
        if($checked == 1) {
            $newTag .= ' #';
        }
        $newTag .= '>';
        return $newTag;
    }

    /**
     * @brief Convert a string to a regex so it can be used in PHP "preg_match" function
     * from dokuwiki searchpattern plugin
     */
    private function _todoStr2regex($str) {
        $regex = ''; //init
        for($i = 0; $i < strlen($str); $i++) { //for each char in the string
            if(!ctype_alnum($str[$i])) { //if char is not alpha-numeric
                $regex = $regex . '\\'; //escape it with a backslash
            }
            $regex = $regex . $str[$i]; //compose regex
        }
        return $regex; //return
    }

}


if(!function_exists('strnpos')) {
    /**
     * Find position of $occurance-th $needle in haystack
     */
    function strnpos($haystack, $needle, $occurance, $pos = 0) {
        for($i = 1; $i <= $occurance; $i++) {
            $pos = strpos($haystack, $needle, $pos) + 1;
        }
        return $pos - 1;
    }
}