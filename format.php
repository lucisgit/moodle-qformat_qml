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
 * Code for importing Questionmark QML question data into Moodle.
 *
 * @package     qformat_qml
 * @author      Tom McCracken <t27m@openmailbox.org>
 * @copyright   2015, Lancaster University ISS
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
defined('MOODLE_INTERNAL') || die();

class qformat_qml extends qformat_default {

    public function provide_import() {
        return true;
    }

    public function mime_type() {
        return 'application/xml';
    }

    /**
     * Parses the uploaded QML data
     * @param string the filename containig the data
     * @return SimpleXML_Object a SimpleXML_Object containing all question data
     */
    public function readdata($filename) {
        // Read the XML into a simpleXMLObject.
        $sxmlref = simplexml_load_file($filename);

        // Simplexml_load_file returns an empty object simpleXMLObject instead of null or false.
        // Return the simpleXMLObject if not empty.
        if (!empty($sxmlref)) {
            return $sxmlref;
        }

        return false;
    }

    /**
     * Populates an array with questions
     * @param SimpleXML_Object contains the question data in a SimpleXML_Object
     * @return array an array of question objects
     */
    // Array to hold the questions.
    public $questions = array();

    public function readquestions($sxmlref) {

        // Iterate through simple_xml question objects.
        foreach ($sxmlref as $xmlquestion) {
            $questiontype = $this->get_question_type($xmlquestion->ANSWER['QTYPE']);
            $qo = null;

            switch ($questiontype) {
                case "multichoice":
                    $qo = $this->import_multichoice($xmlquestion);
                    break;
                case "truefalse":
                    $qo = $this->import_truefalse($xmlquestion);
                    break;
                case "shortanswer":
                    $qo = $this->import_shortanswer($xmlquestion);
                    break;
                case "essay":
                    $qo = $this->import_essay($xmlquestion);
                    break;
                case "numerical":
                    $qo = $this->import_numerical($xmlquestion);
                    break;
                default:
                    $qtstr = (string) $questiontype;
                    $this->error(get_string('unknownquestiontype', 'qformat_qml', $qtstr));
                    break;
            }

            // Add the result into the $questions array.
            if ($qo) {
                $qo->generalfeedback = '';
                $this->questions[] = $qo;
            }
        }

        return $this->questions;
    }

    /**
     * Bootstrap a question object
     * @param SimpleXML_Object contains the question data in a SimpleXML_Object
     * @return object question object
     */
    public function import_headers($xmlquestion) {
        // Initalise question object.
        $qo = $this->defaultquestion();

        $qtext = trim((string) $xmlquestion->CONTENT);
        $qname = trim((string) $xmlquestion['DESCRIPTION']);

        if (strlen($qname) == 0) {
            $qname = $qtext;
        }

        if (strlen($qtext) == 0) {
            $qtext = $qname;
        }

        $qo->name = $qname;
        $qo->questiontext = $qtext;
        $qo->questiontextformat = 0; // Moodle_auto_format.
        $qo->generalfeedback = "";
        $qo->generalfeedbackformat = 1;
        $qo->feedbackformat = FORMAT_MOODLE;

        $qo->category = $this->import_category($xmlquestion);

        // Get the content type for this question.
        $contenttype = (string) $xmlquestion->CONTENT['TYPE'];

        switch ($contenttype) {
            case "text/plain":
                $qo->questiontextformat = 2; // Plain_text.
                break;
            case "text/html":
                $qo->questiontextformat = 1; // HTML.
                break;
            default:
                echo get_string('contenttypenotset', 'qformat_qml');
                $qo->questiontextformat = 1; // HTML.
        }

        return $qo;
    }

    /**
     * Import question category
     * @param array question question array from xml tree
     * @return string the category name
     */
    public function import_category($xmlquestion) {
        $qo = new stdClass();
        $qo->qtype = 'category';
        $qo->category = (string) $xmlquestion["TOPIC"];
        $this->questions[] = $qo;
        return $qo->category;
    }

    /**
     * Import numerical type question
     * @param array question question array from xml tree
     * @return object question object
     */
    public function import_numerical($xmlquestion) {
        // Get common parts.
        $qo = $this->import_headers($xmlquestion);

        // Header parts particular to numerical.
        $qo->qtype = 'numerical';

        $qo->answer = array();
        $qo->feedback = array();
        $qo->fraction = array();

        $ans = substr((string) $xmlquestion->OUTCOME[0]->CONDITION, 5);
        $qo->answer[] = $ans;

        if (empty($qo->answer)) {
            $qo->answer = '*';
        }

        $qo->feedback[] = array("text" => "", "format" => FORMAT_MOODLE);
        $qo->tolerance[] = 0;

        // Deprecated?
        $qo->fraction[] = 1;

        // Default moodles values are set for QML imported questions.
        $qo->unitgradingtype = 0;
        $qo->unitpenalty = 0.1000000;
        $qo->showunits = 3;
        $qo->unitsleft = 0;
        $qo->instructions['text'] = '';
        $qo->instructions['format'] = FORMAT_HTML;

        return $qo;
    }

    /**
     * Import essay type question
     * @param array question question array from xml tree
     * @return object question object
     */
    public function import_essay($xmlquestion) {
        // Get common parts.
        $qo = $this->import_headers($xmlquestion);

        // Header parts particular to essay.
        $qo->qtype = 'essay';

        $qo->responseformat = "editor";
        $qo->responsefieldlines = 20;
        $qo->responserequired = 1;
        $qo->attachments = 0;
        $qo->attachmentsrequired = 0;
        $qo->graderinfo = array("text" => "", "format" => FORMAT_MOODLE);
        $qo->responsetemplate['text'] = "";
        $qo->responsetemplate['format'] = "";

        return $qo;
    }

    /**
     * Import a multichoice question
     * @param SimpleXML_Object contains the question data in a SimpleXML_Object
     * @return object question object
     */
    public function import_multichoice($xmlquestion) {
        // Common question headers.
        $qo = $this->import_headers($xmlquestion);

        // Header parts particular to multichoice.
        $qo->qtype = 'multichoice';
        $qo->answernumbering = 'abc';
        $qo->single = 0;
        $qo->shuffleanswers = 0;

        // Answer count.
        $acount = 0;

        $ansconditiontext = (string) $xmlquestion->OUTCOME[0]->CONDITION;

        /* It is possible that $ansConditionText will be: "0" or "1", but we want a
         * condition string such as: NOT "0" AND NOT "1" AND NOT "2" AND NOT "3" AND "4"
         */
        if (strlen($ansconditiontext) <= 3) {
            $ansconditiontext = $this->build_logical_answer_string($xmlquestion);
        }

        // Parse the logical answer string into an array of fractions.
        $anscondition = $this->parse_answer_condition($ansconditiontext);

        // Set some default values for feedback.
        $qo->correctfeedback = array("text" => "Correct", "format" => FORMAT_MOODLE);
        $qo->partiallycorrectfeedback = array("text" => "Partly Correct", "format" => FORMAT_MOODLE);
        $qo->incorrectfeedback = array("text" => "Incorrect", "format" => FORMAT_MOODLE);

        // Loop the answers and set the correct fraction and default feedback for each.
        foreach ($xmlquestion->children() as $child) {
            if ($child->getName() == "ANSWER") {
                foreach ($child->children() as $anschild) {
                    if ($anschild->getName() == "CHOICE") {
                        $anstext = (string) $anschild->CONTENT;
                        $ansfraction = $anscondition[$acount];

                        $qo->answer[$acount] = array("text" => $anstext, "format" => FORMAT_MOODLE);
                        $qo->fraction[$acount] = $ansfraction;
                        $qo->feedback[$acount] = array("text" => "Incorrect", "format" => FORMAT_MOODLE);
                        if ($ansfraction > 0) {
                            $qo->feedback[$acount] = array("text" => "Correct", "format" => FORMAT_MOODLE);
                        }

                        ++$acount;
                    }
                }
                break;
            }
        }

        return $qo;
    }

    /**
     * Builds a logical string to parse the correct answers. Used when the 
     * mulichoice question has seperate answers for the question.
     * @param SimpleXMLObject the XML question object
     * @return string a logical string to be used for the parse_answer_condition method
     */
    private function build_logical_answer_string($xmlquestion) {

        $ansstring = "";
        $acount = 0;

        foreach ($xmlquestion->children() as $child) {
            if ($child->getName() == "OUTCOME") {

                if ($child['SCORE'] == 0) {
                    $ansstring .= "NOT ";
                }

                $ansstring .= '"' . $acount . '"' . ' ';
            }

            ++$acount;
        }

        return $ansstring;
    }

    /**
     * Calculate the correct fraction for each answer
     * @param string logical string identifying the correct answer sequence
     * @return array contains the fractions for each answer
     */
    private function parse_answer_condition($ansconditiontext) {

        $anscount = 0;
        $anstextparts = explode(' ', $ansconditiontext);
        $fractionarr = array();

        // Count the number of answers and clean the array of empty strings.
        $anstextcount = count($anstextparts);
        for ($cntr = 0; $cntr < $anstextcount; $cntr++) {
            if (strlen($anstextparts[$cntr]) == 0) {
                unset($anstextparts[$cntr]);
            } else if (strpos($anstextparts[$cntr], '"') !== false) {
                $anscount++;
            }
        }

        $correctanscount = 0;

        /* Populate the fraction array with the answer fractions
         * Checks to see if the current text value of the condition string
         * is "NOT", if it is then a not flag is set ($last_value_not) to true
         * and when the next answer value is detected ( a string with " e.g. "0")
         * the value for that answer is set to 0; otherwise the answer value is
         * set to answers worth;
         */
        $lastvaluenot = false;
        foreach ($anstextparts as $anstextpart) {
            if ($anstextpart == "NOT") {
                $lastvaluenot = true;
            } else if (strpos($anstextpart, '"') !== false) {
                if ($lastvaluenot) {
                    $fractionarr[] = 0;
                    $lastvaluenot = false;
                } else {
                    $fractionarr[] = 1;
                    $correctanscount++;
                }
            }
        }

        // Calculate how much each correct answer is actually worth.
        $correctansworth = 1 / $correctanscount;

        for ($cntr = 0; $cntr < count($fractionarr); $cntr++) {
            if ($fractionarr[$cntr] == 1) {
                $fractionarr[$cntr] = $correctansworth;
            }
        }
        return $fractionarr;
    }

    /**
     * Import true or false question
     * @param SimpleXML_Object contains the question data in a SimpleXML_Object
     * @return object question object
     */
    public function import_truefalse($xmlquestion) {
        // Get common parts.
        $qo = $this->import_headers($xmlquestion);

        // Header parts particular to true/false.
        $qo->qtype = 'truefalse';

        // Assume the first answer ID is for the true answer.
        $trueqid = 0;

        // The text value of the node either True or False.
        $answertext = strtolower((string) $xmlquestion->ANSWER->CHOICE[$trueqid]->CONTENT);

        // Populate the feedback array and set correct answer.
        if ($answertext == 'true') {
            $qo->feedbacktrue = array(
                "text" => (string) $xmlquestion->OUTCOME[$trueqid]->CONTENT,
                "format" => FORMAT_MOODLE
            );
            $qo->feedbackfalse = array(
                "text" => (string) $xmlquestion->OUTCOME[$trueqid + 1]->CONTENT,
                "format" => FORMAT_MOODLE
            );

            $qo->answer = $xmlquestion->OUTCOME[$trueqid]['SCORE'] == 1 ? true : false;
            $qo->correctanswer = $qo->answer;
        } else {
            $qo->feedbacktrue = array(
                "text" => (string) $xmlquestion->OUTCOME[$trueqid + 1]->CONTENT,
                "format" => FORMAT_MOODLE
            );
            $qo->feedbackfalse = array(
                "text" => (string) $xmlquestion->OUTCOME[$trueqid]->CONTENT,
                "format" => FORMAT_MOODLE
            );
            $qo->answer = $xmlquestion->OUTCOME[$trueqid + 1]['SCORE'] == 1 ? true : false;
            $qo->correctanswer = $qo->answer;
        }

        return $qo;
    }

    /**
     * Import a shortanswer question
     * @param SimpleXML_Object xml object containing the question data
     * @return object question object
     */
    public function import_shortanswer($xmlquestion) {
        // Get common parts.
        $qo = $this->import_headers($xmlquestion);

        // Header parts particular to shortanswer.
        $qo->qtype = 'shortanswer';

        // Ignore case for all FIB questions.
        $qo->usecase = 0;

        /* Find out if the question has a single condition string
         * fib_type = "right" means that the condition string is not in more
         * than one part.
         */
        $fibtype = $xmlquestion->OUTCOME[0]["ID"];
        $hasmultianswer = strpos((string) $xmlquestion->OUTCOME->CONDITION, "AND") !== false;

        if ($hasmultianswer || $fibtype == "0") {
            $qo = $this->import_multi_answer_fib($xmlquestion, $qo);
        } else if (!$hasmultianswer && $fibtype != "0") {
            $qo = $this->import_textmatch($xmlquestion, $qo);
        } else {
            $qo = $this->import_fib($xmlquestion, $qo);
        }

        return $qo;
    }

    /**
     * Imports a textmatch question as a shortanswer
     * @param SimpleXML_Object $xmlquestion the SimpleXML_Object question object
     * @param object $qo the question object
     * @return object returns the question object
     */
    private function import_textmatch($xmlquestion, $qo) {
        $qtext = addslashes(trim((string) $xmlquestion->CONTENT));
        $ansqtext = (string) $xmlquestion->ANSWER->CONTENT;

        if (strlen($qtext) == 0) {
            $qtext = (strlen($ansqtext) > 0) ? $ansqtext : "COULD NOT LOCATE QUESTION TEXT IN XML FILE";
            // Display notice to user or consider this an error?
        }

        if ($qo->name == "Fill in Blanks question") {
            $qo->name = $qtext;
        }

        $qo->questiontext = $qtext;

        if (!empty($xmlquestion->OUTCOME[0])) {
            $ansconditiontext = (string) $xmlquestion->OUTCOME[0]->CONDITION;
        }

        if (isset($ansconditiontext)) {
            if (strpos($ansconditiontext, '=') !== false) {
                $anstext = substr($ansconditiontext, 5);
            } else {
                $anstext = $this->break_logical_ans_str($ansconditiontext);
            }
            $qo->feedback[] = array("text" => "Correct", "format" => FORMAT_MOODLE);
            $qo->feedback[] = array("text" => "Incorrect", "format" => FORMAT_MOODLE);
            $qo->fraction[] = 1;
            $qo->answer[] = trim($anstext);
        } else {
            $this->error("Shortanswer questions with no correct answer are not"
                    . " supported in moodle");
            $qo = null;
        }

        return $qo;
    }

    /**
     * Import a fill in the blanks question
     * @param SimpleXML_Object xml object containing the question data
     * @param object a partly populated question object to work with
     * @return object the modified question object
     */
    private function import_fib($xmlquestion, $qo) {

        $qtext = "";

        $ansconditiontext = (string) $xmlquestion->OUTCOME[0]->CONDITION;
        $correctansfeedback = (string) $xmlquestion->OUTCOME[0]->CONTENT;
        $incorrectansfeedback = (string) $xmlquestion->OUTCOME[1]->CONTENT;

        $qo->feedback[] = array("text" => $correctansfeedback, "format" => FORMAT_MOODLE);
        $qo->feedback[] = array("text" => $incorrectansfeedback, "format" => FORMAT_MOODLE);

        // How much is this answer worth for this question.
        $qo->fraction[] = 1;

        // Loop the question object.
        foreach ($xmlquestion->children() as $child) {
            // We only care about the answer node.
            if ($child->getName() == "ANSWER") {
                foreach ($child->children() as $anschild) {

                    // Append the text contained in this Answer->Content node.
                    if ($anschild->getName() == "CONTENT") {
                        $qtext .= (string) $anschild;
                    }

                    /* Append a _ character instead of the answer.
                     * i.e. to show there is a blank that should go here.
                     */
                    if ($anschild->getName() == "CHOICE") {
                        $qtext .= ' _ ';
                    }
                }
            }
        }

        $anstext = $this->break_logical_ans_str((string) $ansconditiontext);

        /* Currently this will only match exact answers regardless of what the
         * exported settings were.  (case-insensitive)
         * Set the value text as our correct answer.
         */
        $qo->answer[] = $anstext;

        // Clean the text.
        $qtext = addslashes(trim((string) $qtext));

        /* Try to overwrite the generic question name with something more descriptive
         * Not nessesary, but a lot of the test files had generic names, this
         * will replace that with part of the question text.
         */
        if ($qo->questiontext == "Fill in Blanks question") {
            $qo->name = substr($qtext, 0, 30);
        }

        // Assign the question text.
        $qo->questiontext = $qtext;

        return $qo;
    }

    private function break_logical_ans_str($logicalstr) {
        /*
         * The CONDITION text has 5 parts
         * NOT | Choices | Operation | Value | Boolean
         * They can be conditionals e.g. a node may look like
         * <CONDITION>"0" MATCHES NOCASE "reduction" OR "0" NEAR NOCASE "reduction"</CONDITION>
         * we only want to know the Value text to match against the users answer.
         */
        $ansparts = explode(" ", $logicalstr);

        // This 'should' be the correct answer.
        $anstext = str_replace('"', "", $ansparts[3]);

        return $anstext;
    }

    /**
     * Import a fill in the blanks question that has a score per blannk
     * @param SimpleXML_Object xml object containing the question data
     * @param object a partly populated question object to work with
     * @return object the modified question object
     */
    private function import_multi_answer_fib($xmlquestion, $qo) {
        question_bank::get_qtype('multianswer');

        /* Parse QML text - import_fib basically does this, but we need to
         * build the Cloze question string in the correct format.
         */
        $questiontext = array();

        // Array holding question data.
        $multiansdata = $this->build_multianswer_string($xmlquestion);

        // Set the questiontext and format.
        $questiontext['text'] = $multiansdata['text'];
        $questiontext['format'] = FORMAT_MOODLE;

        $qo = qtype_multianswer_extract_question($questiontext);

        // Set values for the question.
        $qo->qtype = 'multianswer';
        $qo->course = $this->course;

        $qo->name = $multiansdata['qname'];
        $qo->questiontextformat = 0;
        $qo->questiontext = $qo->questiontext['text'];

        $qo->generalfeedback = '';
        $qo->generalfeedbackformat = FORMAT_MOODLE;
        $qo->length = 1;
        $qo->penalty = 0.3333333;

        return $qo;
    }

    /**
     * Builds the Cloze question text string and the question name
     * @param SimpleXML_Object xml object containing the question data
     * @param boolean true if this question is a Questionmark multi score FIB
     * @return array an array containing the questiontext and question name
     */
    private function build_multianswer_string($xmlquestion) {

        $ignored = array("MATCHES", "NOCASE", "NEAR", "AND", "OR");

        // String to hold the question name, used by the calling function.
        $qname = "";

        // The question type.
        $qtype = ":SHORTANSWER:";

        $ansconditiontext = "";

        foreach ($xmlquestion->children() as $child) {
            $nodename = $child->getName();
            if ($nodename == "OUTCOME" && $child['ID'] != 'wrong' && $child['ID'] != 'Always happens') {
                $condition = (string) $child->CONDITION;

                if (strpos($condition, '=')) {
                    $condition = substr($condition, 5);
                }

                $ansconditiontext .= $condition . ' ';
            }
        }

        // Question text.
        $qtext = "";

        // Array to hold all of the parsed cloze strings.
        $clozeanswers = array();

        // Question answers.
        $qanswers = array();

        /*  Cloze question key
         *  { start the cloze sub-question with a bracket
         *  INT define a grade for each cloze by a number (optional). This used for calculation of question grading.
         *  :SHORTANSWER: define the type of cloze sub-question. Definition is bounded by ':'.
         *  ~ is a seperator between answer options = marks a correct answer
         *  # marks the beginning of an (optional) feedback message
         *  } close the cloze sub-question at the end with a bracket (AltGr+0)
         *
         *   Example of Cloze question string
         *   'The capital of France is
         *   {
         *      1
         *      :SHORTANSWER:
         *      %100%Paris
         *      #Congratulations!
         *      ~
         *      %50%Marseille
         *      #No, that is the second largest city in France (after Paris).
         *      ~
         *      *
         *      #Wrong answer. The capital of France is Paris, of course.
         *   }';
         */

        // Split the string up by the space character.
        $ansparts = explode(" ", (string) $ansconditiontext);

        /* Loop used to iterate the logical condition answer string and build
         * up each of the cloze answer strings.
         */
        foreach ($ansparts as $anspart) {

            $text = str_replace('"', "", $anspart);

            // If we have a "MATCHES" in the answer condition string, we then ignore numerical answers.
            if (strpos($ansconditiontext, "MATCHES") !== false) {
                if (is_numeric($text)) {
                    continue;
                }
            }

            // Ignore answer condition data, otherwise this could be considered the answer.
            if (in_array($text, $ignored)) {
                continue;
            }

            // Ignore empty strings.
            if (empty($text)) {
                continue;
            }

            // Start the answer.
            $clozequestionformat = '{';

            // Set the grade for this answer.
            $clozequestionformat .= 1;

            // Set the question format for this answer.
            $clozequestionformat .= $qtype;

            // Add the correct answer text.
            $clozequestionformat .= "=" . $text;

            // Store the answer value for this question.
            $qanswers[] = $text;

            // Close the answer bracket.
            $clozequestionformat .= '}';

            // Add the parsed string to the array.
            $clozeanswers[] = $clozequestionformat;
        }

        // Index to keep track of the current answer, used to build the question name.
        $answerindex = 0;

        /* Loop used to build the question text and insert the already parsed
         * cloze question strings. Creates the question name.
         */
        foreach ($xmlquestion->children() as $child) {
            // We only care about the answer node.
            if ($child->getName() == "ANSWER") {
                foreach ($child->children() as $anschild) {

                    // Append the text contained in this Answer->Content node.
                    if ($anschild->getName() == "CONTENT") {
                        $qtext .= (string) $anschild;
                        $qname .= (string) $anschild;
                    }

                    /* Append the parsed cloze string to mark the "blank"
                     * i.e. to show there is a blank that should go here
                     */
                    if ($anschild->getName() == "CHOICE") {
                        $qtext .= " {$clozeanswers[$answerindex]} ";
                        $qname .= " {{$qanswers[$answerindex]}} ";
                        ++$answerindex;
                    }
                }
            }
        }

        if (strlen($qname) == 0) {
            $qname = $qtext;
        }

        $qname = substr($qname, 0, 240);

        return array('text' => $qtext, 'qname' => $qname . '...');
    }

    /**
     * Gets the moodle equivelant of the Questionmark question type
     * @param string the Questionmark question type string
     * @return string the moodle question type string
     */
    private function get_question_type($strqtype) {
        $mdlquestiontype = "";
        switch ($strqtype) {
            case "MR":
            case "MC":
                $mdlquestiontype = "multichoice";
                break;
            case "TF":
                $mdlquestiontype = "truefalse";
                break;
            case "FIB":
            case "TM":
                $mdlquestiontype = "shortanswer";
                break;
            case "ESSAY":
                $mdlquestiontype = "essay";
                break;
            case "NUM":
                $mdlquestiontype = "numerical";
                break;
            default :
                $mdlquestiontype = $strqtype;
                break;
        }

        return $mdlquestiontype;
    }

}
