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
 * Code for importing Questionmark QML questions into Moodle.
 *
 * @package    qformat_qml
 * @copyright  2015, Lancaster University ISS
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
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
        // Read the XML into a simpleXMLObject
        $sxmlref = simplexml_load_file($filename);

        // simplexml_load_file returns an empty object simpleXMLObject instead of null or false
        // return the simpleXMLObject if not empty
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
    public function readquestions($sxmlref) {

        //array to hold the questions
        $questions = array();

        //Iterate through simple_xml question objects
        foreach ($sxmlref as $xml_question) {
            $question_type = $this->get_question_type($xml_question->ANSWER['QTYPE']);
            $qo = null;

            switch ($question_type) {
                case "multichoice":
                    $qo = $this->import_multichoice($xml_question);
                    break;
                case "truefalse":
                    $qo = $this->import_truefalse($xml_question);
                    break;
                case "shortanswer":
                    $qo = $this->import_shortanswer($xml_question);
                    break;
                default:
                    $qtstr = (string) $question_type;
                    $this->error(get_string('unknownquestiontype', 'qformat_qml', $qtstr));
                    break;
            }

            // Add the result into the $questions array
            if ($qo) {
                $qo->generalfeedback = '';
                $questions[] = $qo;
            }
        }

        return $questions;
    }

    /**
     * Bootstrap a question object
     * @param SimpleXML_Object contains the question data in a SimpleXML_Object
     * @return object question object
     */
    public function import_headers($xml_question) {
        //initalise question object
        $qo = $this->defaultquestion();

        $qText = trim((string) $xml_question['DESCRIPTION']);
        $qo->name = $qText;
        $qo->questiontext = $qText;
        $qo->questiontextformat = 0; // moodle_auto_format
        $qo->generalfeedback = "";
        $qo->generalfeedbackformat = 1;
        $qo->feedbackformat = FORMAT_MOODLE;

        //get the content type for this question
        $content_type = (string) $xml_question->CONTENT['TYPE'];

        switch ($content_type) {
            case "text/plain":
                $qo->questiontextformat = 2; // plain_text
                break;
            case "text/html":
                $qo->questiontextformat = 1; // html
                break;
            default:
                echo get_string('contenttypenotset', 'qformat_qml');
                $qo->questiontextformat = 1; // html
        }

        return $qo;
    }

    /**
     * Import a multichoice question
     * @param SimpleXML_Object contains the question data in a SimpleXML_Object
     * @return object question object
     */
    public function import_multichoice($xml_question) {
        // Common question headers
        $qo = $this->import_headers($xml_question);

        // Header parts particular to multichoice.
        $qo->qtype = 'multichoice';
        $qo->answernumbering = 'abc';
        $qo->single = 0;
        $qo->shuffleanswers = 0;

        // Answer count
        $acount = 0;

        // Answer text
        $ansText = "";

        // Array holding to hold the correct answer fractions
        $ansCondition = array();

        $ansConditionText = (string) $xml_question->OUTCOME[0]->CONDITION;

        // It is possible that this text will be: "0" or "1", but we want a
        // condition string such as: NOT "0" AND NOT "1" AND NOT "2" AND NOT "3" AND "4"
        if (strlen($ansConditionText) <= 3) {
            $ansConditionText = $this->build_logical_answer_string($xml_question);
        }

        // Parse the logical answer string into an array of fractions
        $ansCondition = $this->parse_answer_condition($ansConditionText);

        // Set some default values for feedback
        $qo->correctfeedback = array("text" => "Correct", "format" => FORMAT_MOODLE);
        $qo->partiallycorrectfeedback = array("text" => "Partly Correct", "format" => FORMAT_MOODLE);
        $qo->incorrectfeedback = array("text" => "Incorrect", "format" => FORMAT_MOODLE);

        // Loop the answers and set the correct fraction and default feedback for each
        foreach ($xml_question->children() as $child) {
            if ($child->getName() == "ANSWER") {
                foreach ($child->children() as $ansChild) {
                    if ($ansChild->getName() == "CHOICE") {
                        $ansText = (string) $ansChild->CONTENT;
                        $ansFraction = $ansCondition[$acount];

                        $qo->answer[$acount] = array("text" => $ansText, "format" => FORMAT_MOODLE);
                        $qo->fraction[$acount] = $ansFraction;
                        $qo->feedback[$acount] = array("text" => "Incorrect", "format" => FORMAT_MOODLE);
                        if ($ansFraction > 0) {
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
    private function build_logical_answer_string($xml_question) {

        $ans_string = "";
        $acount = 0;

        foreach ($xml_question->children() as $child) {
            if ($child->getName() == "OUTCOME") {

                if ($child['SCORE'] == 0) {
                    $ans_string .= "NOT ";
                }

                $ans_string .= '"' . $acount . '"' . ' ';
            }

            ++$acount;
        }

        return $ans_string;
    }

    /**
     * Calculate the correct fraction for each answer
     * @param string logical string identifying the correct answer sequence
     * @return array contains the fractions for each answer
     */
    private function parse_answer_condition($ans_condition_text) {

        $ansCount = 0;
        $ans_text_parts = explode(' ', $ans_condition_text);
        $fraction_arr = array();

        // Count the number of answers and clean the array of empty strings
        $ans_text_count = count($ans_text_parts);
        for ($cntr = 0; $cntr < $ans_text_count; $cntr++) {
            if (strlen($ans_text_parts[$cntr]) == 0) {
                unset($ans_text_parts[$cntr]);
            } else if (strpos($ans_text_parts[$cntr], '"') !== FALSE) {
                $ansCount++;
            }
        }

        $correct_ans_count = 0;

        // Populate the fraction array with the answer fractions
        // Checks to see if the current text value of the condition string
        // is "NOT", if it is then a not flag is set ($last_value_not) to true
        // and when the next answer value is detected ( a string with " e.g. "0")
        // the value for that answer is set to 0; otherwise the answer value is
        // set to answers worth;
        $last_value_not = false;
        foreach ($ans_text_parts as $ans_text_part) {
            if ($ans_text_part == "NOT") {
                $last_value_not = true;
            } else if (strpos($ans_text_part, '"') !== FALSE) {
                if ($last_value_not) {
                    $fraction_arr[] = 0;
                    $last_value_not = false;
                } else {
                    $fraction_arr[] = 1;
                    $correct_ans_count++;
                }
            }
        }

        // Calculate how much each correct answer is actually worth
        $correct_ans_worth = 1 / $correct_ans_count;

        for ($cntr = 0; $cntr < count($fraction_arr); $cntr++) {
            if ($fraction_arr[$cntr] == 1) {
                $fraction_arr[$cntr] = $correct_ans_worth;
            }
        }

        return $fraction_arr;
    }

    /**
     * Import true or false question
     * @param SimpleXML_Object contains the question data in a SimpleXML_Object
     * @return object question object
     */
    public function import_truefalse($xml_question) {
        // get common parts
        $qo = $this->import_headers($xml_question);

        // Header parts particular to true/false.
        $qo->qtype = 'truefalse';

        // Assume the first answer ID is for the true answer.
        $true_q_id = 0;

        // The text value of the node either True or False
        $answertext = strtolower((string) $xml_question->ANSWER->CHOICE[$true_q_id]->CONTENT);

        // Populate the feedback array and set correct answer
        if ($answertext == 'true') {
            $qo->feedbacktrue = array(
                "text" => (string) $xml_question->OUTCOME[$true_q_id]->CONTENT,
                "format" => FORMAT_MOODLE
            );
            $qo->feedbackfalse = array(
                "text" => (string) $xml_question->OUTCOME[$true_q_id + 1]->CONTENT,
                "format" => FORMAT_MOODLE
            );

            $qo->answer = $xml_question->OUTCOME[$true_q_id]['SCORE'] == 1 ? true : false;
            $qo->correctanswer = $qo->answer;
        } else {
            $qo->feedbacktrue = array(
                "text" => (string) $xml_question->OUTCOME[$true_q_id + 1]->CONTENT,
                "format" => FORMAT_MOODLE
            );
            $qo->feedbackfalse = array(
                "text" => (string) $xml_question->OUTCOME[$true_q_id]->CONTENT,
                "format" => FORMAT_MOODLE
            );
            $qo->answer = $xml_question->OUTCOME[$true_q_id + 1]['SCORE'] == 1 ? true : false;
            $qo->correctanswer = $qo->answer;
        }

        return $qo;
    }

    /**
     * Import a shortanswer question
     * @param SimpleXML_Object xml object containing the question data
     * @return object question object
     */
    public function import_shortanswer($xml_question) {
        // Get common parts.
        $qo = $this->import_headers($xml_question);

        // Header parts particular to shortanswer.
        $qo->qtype = 'shortanswer';

        // Each blank answer from Questionmark can have it's own case setting
        // moodle only supports a single setting per shortanswer question
        $qo->usecase = 0; // Ignore case for all FIB questions. ^^
        // Find out what type of FIB question we are dealing with
        // e.g. Does the question have one answer or multiple blanks to answer
        // A single answer question has the outcome ID of "right"
        $fib_type = $xml_question->OUTCOME[0]["ID"];

        if ($fib_type == "right") {
            //The question can still have multiple blanks, but only a single score
            $multi_answer = strpos((string) $xml_question->OUTCOME->CONDITION, "AND");

            if ($multi_answer !== FALSE) {
                $qo = $this->import_fib($xml_question, $qo, true, false);
            } else {
                $qo = $this->import_fib($xml_question, $qo, false, false);
            }
        } else if ($fib_type == 0) {
            $this->import_fib($xml_question, $qo, true, true);
        } else {
            $this->error("Unable to determine question type");
        }

        return $qo;
    }

    /**
     * Import a fill in the blanks question
     * @param SimpleXML_Object xml object containing the question data
     * @param object a partly populated question object to work with
     * @param boolean true if this question has multiple 'blanks' in it's answer
     * @return object the modified question object
     */
    private function import_fib($xml_question, $qo, $isMultipleAns, $isMultipleScore) {

        $qText = "";
        $ansText = "";
        $correct_ans_feedback = "Correct";
        $incorrect_ans_feedback = "Incorrect";
        $ansConditionText = "";

        if (!$isMultipleScore) {
            $ansConditionText = (string) $xml_question->OUTCOME[0]->CONDITION;
            $correct_ans_feedback = (string) $xml_question->OUTCOME[0]->CONTENT;
            $incorrect_ans_feedback = (string) $xml_question->OUTCOME[1]->CONTENT;
        }

        $qo->feedback[] = array("text" => $correct_ans_feedback, "format" => FORMAT_MOODLE);
        $qo->feedback[] = array("text" => $incorrect_ans_feedback, "format" => FORMAT_MOODLE);

        // How much is this answer worth for this question. 
        $qo->fraction[] = 1;

        foreach ($xml_question->children() as $child) {
            // Get the ID of the first choice node
            if ($child->getName() == "ANSWER") {
                foreach ($child->children() as $ansChild) {
                    if ($ansChild->getName() == "CHOICE") {
                        $qText .= ' _ ';
                    }

                    if ($ansChild->getName() == "CONTENT") {
                        $qText .= (string) $ansChild;
                    }
                }
            }
            
            if($child->getName() == "OUTCOME") {
                if($child['ID'] != "Always happens") {
                    $ansConditionText .= (string)$child->CONDITION . " ";
                }
            }
        }
        
        echo $ansConditionText;

        // The CONDITION text has 5 parts
        // NOT | Choices | Operation | Value | Boolean
        // They can be conditionals e.g. a node may look like
        // <CONDITION>"0" MATCHES NOCASE "reduction" OR "0" NEAR NOCASE "reduction"</CONDITION>
        // we only want to know the Value text to match against the users answer.
        $ansParts = explode(" ", (string) $ansConditionText);

        // TODO - Test to ensure that the correct answer will always be found at the 3rd index
        $ansText = str_replace('"', "", $ansParts[3]);

        // Build the answer string by adding each answer separated by a comma
        // TODO - (FIX?) if the users knows part of the missing answer,
        // but not the whole thing, they will get this question wrong.
        if ($isMultipleAns) {
            $ansText = "";
            foreach ($ansParts as $ansPart) {
                if (strpos($ansPart, '"') !== FALSE) {
                    $text = str_replace('"', "", $ansPart);

                    if (is_numeric($text) !== TRUE) {
                        $ansText .= $text . ',';
                    }
                }
            }

            // Trim the far right comma from the answer text.
            $ansText = rtrim($ansText, ',');
        }

        // Currently this will only match exact answers regardless of what the
        // exported settings were.  (case-insensitive)                                
        // Set the value text as our correct answer.
        $qo->answer[] = $ansText;

        //Questionmark FIB questions can have multiple "blanks", moodle doesn't support
        //this question type by default
        //Current solution is to seperate answers via a comma e.g "THE BLANK1,BLANK2"

        $qText = addslashes(trim((string) $qText));

        // Try to overwrite the generic question name with something more descriptive
        if ($qo->questiontext == "Fill in Blanks question") {
            $qo->name = $qText;
        }

        $qo->questiontext = $qText;

        // Overwrite the question text for this queston type
        if ($isMultipleAns) {
            $qo->questiontext .= get_string('blankmultiquestionhint', 'qformat_qml');
        }

        return $qo;
    }

    /**
     * Import a fill in the blanks question that has a score per blannk
     * @param SimpleXML_Object xml object containing the question data
     * @param object a partly populated question object to work with
     * @return object the modified question object
     */
    private function import_multi_score_fib($xml_question, $qo) {
        
    }

    /**
     * Gets the moodle equivelant of the Questionmark question type
     * @param string the Questionmark question type string
     * @return string the moodle question type string
     */
    private function get_question_type($str_QTYPE) {
        $mdl_questiontype = "";
        switch ($str_QTYPE) {
            case "MR":
            case "MC":
                $mdl_questiontype = "multichoice";
                break;
            case "TF":
                $mdl_questiontype = "truefalse";
                break;
            case "FIB":
                $mdl_questiontype = "shortanswer";
                break;
            default :
                $mdl_questiontype = $str_QTYPE;
                break;
        }

        return $mdl_questiontype;
    }

    /**
     * A recursive function to traverse the SimpleXML_Object
     * @param SimpleXML_Object the xml object to traverse
     */
    private function display_xml($xml) {

        foreach ($xml->children() as $child) {
            foreach ($child->attributes() as $attr => $attrVal) {
                if (!empty($child->children())) {
                    $this->get_xml_entities($child);
                }
            }
        }
    }

}
