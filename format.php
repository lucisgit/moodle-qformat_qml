<?php

defined('MOODLE_INTERNAL') || die();

class qformat_qml extends qformat_default {

    public function provide_import() {
        return true;
    }

    public function mime_type() {
        return 'application/xml';
    }

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

        //content tags
        $content_type = $xml_question->CONTENT['TYPE'];

        switch ($content_type) {
            case "text/plain":
                $qo->questiontextformat = 2; // plain_text
                break;
            case "text/html":
                $qo->questiontextformat = 1; // html
                break;
            default:
                $this->error("Unknown content type in question header ($content_type)");
        }

        return $qo;
    }

    public function import_multichoice($xml_question) {
        // Common question headers
        $qo = $this->import_headers($xml_question);

        // Header parts particular to multichoice.
        $qo->qtype = 'multichoice';
    }

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
                $this->import_fib($xml_question, $qo, true);
            } else {
                $this->import_fib($xml_question, $qo, false);
            }
        } else if ($fib_type == 0) {
            //$this->import_multi_score_fib($xml_question, $qo);
            $this->error("This question type (multiple score per blank) is not supported yet.");
        } else {
            $this->error("Unable to determine question type");
        }

        return $qo;
    }

    // Questionmark questions with a single answer
    private function import_fib($xml_question, &$qo, $isMultipleAns) {

        $qText = "";

        $acount = 0;
        $ansCount = 0;
        $ansText = "";

        //TODO - (check)Make it work with single answer fib

        foreach ($xml_question->children() as $child) {
            // Get the ID of the first choice node
            if ($child->getName() == "ANSWER") {
                //if ($isMultipleAns) {
                    foreach ($child->children() as $ansChild) {
                        if ($ansChild->getName() == "CHOICE") {
                            $qText .= ' _ ';
                        }

                        if ($ansChild->getName() == "CONTENT") {
                            $qText .= (string) $ansChild;
                        }
                    }
                //}
            }

            // Loop the outcome nodes
            if ($child->getName() == "OUTCOME") {
                foreach ($child->children() as $outcome) {
                    if ($outcome->getName() == "CONDITION") {
                        // Does the choice ID match the condition ID
                        if (strpos((string) $outcome, "$acount") !== FALSE) {

                            // This condition is for the correct answer
                            if ($child['ID'] == "right") {
                                $qo->fraction[$acount] = $child['SCORE'];

                                // The CONDITION text has 5 parts
                                // NOT | Choices | Operation | Value | Boolean
                                // They can be conditionals e.g. a node may look like
                                // <CONDITION>"0" MATCHES NOCASE "reduction" OR "0" NEAR NOCASE "reduction"</CONDITION>
                                // we only want to know the Value text to match against the users answer.
                                $ansParts = explode(" ", (string) $child->CONDITION);

                                if ($isMultipleAns) {
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
                                    
                                } else {
                                    $ansText = str_replace('"', "", $ansParts[3]); 
                                }

                                // Currently this will only match exact answers regardless of what the
                                // exported settings were.                                
                                // Set the value text as our correct answer.
                                $qo->answer[$acount] = $ansText;

                                //Questionmark FIB questions can have multiple "blanks", moodle doesn't support
                                //this question type by default
                                //Possible way would be to set the question answer text to: "THE BLANK1, BLANK2"
                                //Seperating answers via comma and allowing spaces for each blank

                                $qo->feedback[$acount] = array("text" => $child->CONTENT, "format" => FORMAT_MOODLE);
                            }
                        }
                    }
                }
            }
        }

        // Overwrite the question text for this queston type
        if ($isMultipleAns) {
            $qo->questiontext = addslashes(trim($qText)) . get_string('blankmultiquestionhint', 'qformat_qml');
        } else {
            $qo->questiontext = addslashes(trim((string) $qText));
        }
    }

    // Questionmark questions with a single score, but multiple answer parts
    //e.g. The {blank} was very {blank} and looked {blank}
    private function import_multi_answer_fib($xml_question, &$qo) {
        
    }

    // Questionmark questions with a score per blank answer
    private function import_multi_score_fib($xml_question, &$qo) {
        
    }

    // Gets the moodle equivelant of the Questionmark question type
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

    private function get_xml_attr($xml, $findAttr) {

        foreach ($xml->children() as $child) {
            foreach ($child->attributes() as $attr => $attrVal) {
                if (!empty($child->children())) {
                    $this->get_xml_entities($child);
                }
            }
        }
    }

}
