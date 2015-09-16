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

define('QTYPE_MC', ':MULTICHOICE:');
define('QTYPE_NM', ':NUMERICAL:');
define('QTYPE_SA', ':SHORTANSWER:');

class qformat_qml extends qformat_default {

    /** @var stdClass The admin config for the QML question format */
    private $adminconfig;

    /**
     * Loads and caches the admin config for the QML question format.
     *
     * @return stdClass The plugin config
     */
    private function get_admin_config() {
        global $CFG;

        if ($this->adminconfig) {
            return $this->adminconfig;
        }
        $this->adminconfig = get_config('qformat_qml');

        return $this->adminconfig;
    }

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
                case 'multichoice':
                    $qo = $this->import_multichoice($xmlquestion);
                    break;
                case 'multianswer' :
                    $qo = $this->import_multianswer($xmlquestion);
                    break;
                case 'truefalse':
                    $qo = $this->import_truefalse($xmlquestion);
                    break;
                case 'shortanswer':
                    $qo = $this->import_shortanswer($xmlquestion);
                    break;
                case 'essay':
                    $qo = $this->import_essay($xmlquestion);
                    break;
                case 'numerical':
                    $qo = $this->import_numerical($xmlquestion);
                    break;
                case 'match':
                    $qo = $this->import_match($xmlquestion);
                    break;
                default:
                    $qtstr = clean_param($questiontype, PARAM_TEXT);
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
     * Parse the HTML content of a question to replace QMP server variables.
     *
     * @param string $content The original question content.
     * @return string The parsed HTML with variables replaced.
     */
    private function parse_question_content($content) {
        $config = $this->get_admin_config();

        if (isset($config->qmpvars)) {
            $servervars = unserialize($config->qmpvars);

            // Replace the server variables in the raw HTML.
            foreach ($servervars as $search => $replace) {
                $content = str_replace($search, $replace, $content);
            }
        }

        // Clean up the parsed HTML and return.
        return clean_param($content, PARAM_RAW);
    }

    /**
     * Bootstrap a question object
     * @param SimpleXML_Object contains the question data in a SimpleXML_Object
     * @return object question object
     */
    public function import_headers($xmlquestion) {
        // Initalise question object.
        $qo = $this->defaultquestion();

        $qtext = $this->parse_question_content($xmlquestion->CONTENT);
        $qname = trim(clean_param($xmlquestion['DESCRIPTION'], PARAM_TEXT));

        if (strlen($qname) == 0) {
            $qname = $qtext;
        }

        if (strlen($qtext) == 0) {
            $qtext = $qname;
        }

        $qo->name = $qname;
        $qo->questiontext = $qtext;
        $qo->questiontextformat = FORMAT_HTML;
        $qo->generalfeedback = '';
        $qo->generalfeedbackformat = FORMAT_HTML;
        $qo->feedbackformat = FORMAT_HTML;

        $qo->category = $this->import_category($xmlquestion);

        // Get the content type for this question.
        $contenttype = clean_param($xmlquestion->CONTENT['TYPE'], PARAM_TEXT);

        switch ($contenttype) {
            case 'text/plain':
                $qo->questiontextformat = 2; // Plain_text.
                break;
            case 'text/html':
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
        $qo->category = clean_param($xmlquestion['TOPIC'], PARAM_TEXT);
        $this->questions[] = $qo;
        return $qo->category;
    }

    /**
     * Import numerical type question
     * @param array question question array from xml tree
     * @return object question object
     */
    public function import_numerical($xmlquestion) {
        // If the question has more than one part, use multianswer instead.
        if (!empty($xmlquestion->ANSWER->CHOICE[1])) {
            return $this->import_multianswer($xmlquestion, QTYPE_NM);
        }

        // Get common parts.
        $qo = $this->import_headers($xmlquestion);

        // Header parts particular to numerical.
        $qo->qtype = 'numerical';

        $qo->answer = array();
        $qo->feedback = array();
        $qo->fraction = array();

        foreach ($xmlquestion->OUTCOME as $outcomenode) {
            $outcomeid = clean_param($outcomenode['ID'], PARAM_TEXT);
            $outcome = new stdClass();
            $conditionparts = explode(' = ', trim(clean_param($outcomenode->CONDITION, PARAM_TEXT)));
            if (count($conditionparts) == 2 ) {
                $outcome->answer = $conditionparts[1];
            }
            if (!$outcome->score = clean_param($outcomenode['SCORE'], PARAM_INT)) {
                if (!$outcome->score = clean_param($outcomenode['ADD'], PARAM_INT)) {
                    $outcome->score = 0;
                }
            }
            $outcome->feedback = array('text' => clean_param($outcomenode->CONTENT, PARAM_RAW), 'format' => FORMAT_HTML);
            $outcomes[$outcomeid] = $outcome;
        }

        if (array_key_exists('right', $outcomes)) {
            $qo->defaultmark = $outcomes['right']->score;
            $qo->answer[] = $outcomes['right']->answer;
            $qo->feedback[] = $outcomes['right']->feedback;
            $qo->tolerance[] = 0;
            $qo->fraction[] = 1;

            if (array_key_exists('Within range', $outcomes)) {
                $qo->answer[] = $outcomes['right']->answer;
                $qo->feedback[] = $outcomes['Within range']->feedback;
                $rangeparts = explode(' to ', $outcomes['Within range']->answer);
                $qo->tolerance[] = ($rangeparts[1] - $rangeparts[0]) / 2;
                $qo->fraction[] = $outcomes['Within range']->score / $qo->defaultmark;
            }
        }

        if (empty($qo->answer)) {
            $qo->answer = '*';
        }

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

        $qo->responseformat = 'editor';
        $qo->responsefieldlines = 20;
        $qo->responserequired = 1;
        $qo->attachments = 0;
        $qo->attachmentsrequired = 0;
        $qo->graderinfo = array('text' => '', 'format' => FORMAT_HTML);
        $qo->responsetemplate['text'] = '';
        $qo->responsetemplate['format'] = '';

        return $qo;
    }

    /**
     * Import a multianswer (embedded) type question.
     *
     * @param SimpleXMLObject $xmlquestion The XML question object
     * @param string $qtype Embedded question type for cloze expression
     * @return stdClass A multianswer type question object
     */
    private function import_multianswer($xmlquestion, $qtype = QTYPE_MC) {
        question_bank::get_qtype('multianswer'); // Ensure the multianswer code is loaded.

        $stems = $this->get_stems($xmlquestion, $qtype);
        $matches = $this->get_matches($xmlquestion, $qtype);
        if ($qtype == QTYPE_MC) {
            $choices = $this->get_choices($xmlquestion);
        }

        // Build up the question text using Moodle cloze syntax.
        $qtext = html_writer::div($this->parse_question_content($xmlquestion->CONTENT));
        $qtext .= html_writer::start_tag('ul');
        foreach ($stems as $stemid => $stemtext) {
            $maxscore = 0;
            $ctext = '';
            foreach ($matches[$stemid] as $match) {
                if ($match->score > 0) {
                    $maxscore += $match->score;
                    $ctext .= '~=' . $match->choice;
                } else {
                    $ctext .= '~' . $match->choice;
                }
                // Add feedback if there is any.
                if ($match->feedback !== '') {
                    $ctext .= '#' . $match->feedback;
                } else if ($match->score <= 0 && !empty($matches['wrong']) && $matches['wrong']->feedback !== '') {
                    $ctext .= '#' . $matches['wrong']->feedback;
                }
                if ($qtype == QTYPE_MC) {
                    unset($choices[$stemid][$match->choice]);
                }
            }
            if ($qtype == QTYPE_MC) {
                // Add any remaining missing choices (with no matches).
                foreach ($choices[$stemid] as $choice) {
                    $ctext .= '~' . $choice;
                    if (!empty($matches['wrong']) && $matches['wrong']->feedback !== '') {
                        $ctext .= '#' . $matches['wrong']->feedback;
                    }
                }
            }
            // Build the text for this sub-question.
            $qtext .= html_writer::start_tag('li');
            $clozetext = '{' . $maxscore . $qtype . substr($ctext, 1) . '}';
            // Search for a series of underscores to replace (assuming these would represent a blank).
            if (preg_match_all('/(_{2,})/', $stemtext, $blank) === 1) {
                $qtext .= str_replace($blank[1], $clozetext, $stemtext);
            } else {
                $qtext .= $stemtext . ' ' . $clozetext;
            }
            $qtext .= html_writer::end_tag('li');
        }
        $qtext .= html_writer::end_tag('ul');

        $questiontext = array();
        $questiontext['text'] = $qtext;
        $questiontext['format'] = FORMAT_HTML;
        $questiontext['itemid'] = '';
        $qo = qtype_multianswer_extract_question($questiontext);
        $qo->questiontext = $qo->questiontext['text'];
        $qo->questiontextformat = FORMAT_HTML;

        $qo->name = trim(clean_param($xmlquestion['DESCRIPTION'], PARAM_TEXT));
        $qo->qtype = 'multianswer';
        $qo->generalfeedback = '';
        $qo->generalfeedbackformat = FORMAT_HTML;

        return $qo;
    }

    /**
     * Gets the values of the choices and returns them as an array.
     * @param SimpleXMLObject $xmlquestion The XML question object
     * @return array An array of choice strings
     */
    private function get_choices($xmlquestion) {
        $choices = array();

        foreach ($xmlquestion->ANSWER->children() as $anschild) {
            if ($anschild->getName() == 'CHOICE') {
                $stemid = clean_param($anschild['ID'], PARAM_TEXT);
                foreach ($anschild->children() as $choicechild) {
                    if ($choicechild->getName() == 'OPTION') {
                        $choice = trim(clean_param($choicechild, PARAM_TEXT)) === ''
                                ? '-' : trim(clean_param($choicechild, PARAM_TEXT));
                        $choices[$stemid][$choice] = $choice;
                    }
                }
            }
        }

        return $choices;
    }

    /**
     * Gets the values of the stem and returns them as an array.
     * @param SimpleXMLObject $xmlquestion The XML question object
     * @param string $qtype Embedded question type for cloze expression
     * @return array An array of stem ids with subquestion text
     */
    private function get_stems($xmlquestion, $qtype = QTYPE_MC) {
        $stems = array();

        foreach ($xmlquestion->ANSWER->children() as $anschild) {
            if ($anschild->getName() == 'CHOICE') {
                $stemid = (int) clean_param($anschild['ID'], PARAM_TEXT);
                if ($qtype == QTYPE_NM) {
                    $stems[$stemid] = clean_param($xmlquestion->ANSWER->CONTENT[$stemid], PARAM_RAW);
                } else {
                    foreach ($anschild->children() as $stemchild) {
                        if ($stemchild->getName() == 'CONTENT') {
                            $stems[$stemid] = clean_param($stemchild, PARAM_RAW);
                        }
                    }
                }
            }
        }

        return $stems;
    }

    /**
     * Creates an object for each stem, containing its matching choice, score and feedback.
     *
     * @param SimpleXMLObject $xmlquestion The XML question object
     * @param string $qtype Embedded question type for cloze expression
     * @return array An array of objects, indexed by stemid
     */
    private function get_matches($xmlquestion, $qtype = QTYPE_MC) {
        $matches = array();

        // Create an array of all available outcomes.
        foreach ($xmlquestion->OUTCOME as $outcomenode) {
            $outcomeidparts = explode(' ', clean_param($outcomenode['ID'], PARAM_TEXT));
            $outcomeid = $outcomeidparts[0];
            $outcome = new stdClass();
            $outcome->conditionstring = trim(clean_param($outcomenode->CONDITION, PARAM_TEXT));
            if (!$outcome->score = clean_param($outcomenode['SCORE'], PARAM_INT)) {
                if (!$outcome->score = clean_param($outcomenode['ADD'], PARAM_INT)) {
                    $outcome->score = 0;
                }
            }
            $outcome->feedback = clean_param($outcomenode->CONTENT, PARAM_RAW);

            // Try to identify any catch-all 'wrong' outcome nodes with a misleading id.
            if ($outcome->conditionstring == 'OTHER' && $outcome->score == 0) {
                $outcomeid = 'wrong';
            }

            $outcomes[$outcomeid] = $outcome;
        }

        // See if there's a combined outcome condition string for the correct answers.
        if (array_key_exists('right', $outcomes)) {
            $conditions = explode(' AND ', $outcomes['right']->conditionstring);
            foreach ($conditions as $condition) {
                $conditionparts = ($qtype == QTYPE_NM) ? explode(' = ', $condition) : explode(' MATCHES ', $condition);
                if (count($conditionparts) == 2 ) {
                    $stemid = trim($conditionparts[0], '"');
                    $match = new stdClass();
                    $match->choice = trim($conditionparts[1], '"') === '' ? '-' : trim($conditionparts[1], '"');
                    $match->score = $outcomes['right']->score;
                    $match->feedback = $outcomes['right']->feedback;
                    $matches[$stemid][] = $match;
                }
            }
        }
        // Otherwise, search for a separate outcome condition string for each of the stem ids.
        else {
            foreach ($outcomes as $outcomeid => $outcome) {
                if (is_number($outcomeid)) {
                    // There may still be a number of possible conditions for each stem id.
                    $conditions = explode(' OR ', $outcome->conditionstring);
                    foreach ($conditions as $condition) {
                        $conditionparts = ($qtype == QTYPE_NM) ? explode(' = ', $condition) : explode(' MATCHES ', $condition);
                        if (count($conditionparts) == 2 ) {
                            $stemid = trim($conditionparts[0], '"');
                            $match = new stdClass();
                            $match->choice = trim($conditionparts[1], '"') === '' ? '-' : trim($conditionparts[1], '"');
                            $match->score = $outcome->score;
                            $match->feedback = $outcome->feedback;
                            $matches[$stemid][] = $match;
                        }
                    }
                }
            }
        }

        // Finally, see if there's any combined feedback for wrong answers.
        if (array_key_exists('wrong', $outcomes) && $outcomes['wrong']->score == 0) {
            $match = new stdClass();
            $match->feedback = $outcomes['wrong']->feedback;
            $matches['wrong'] = $match;
        }

        return $matches;
    }

    /**
     * Import a Match type question.
     * @param array Question array from xml tree
     * @return object Question object
     */
    public function import_match($xmlquestion) {
        $qo = $this->import_headers($xmlquestion);

        // Stores all the values required for the question.
        $qo->qtype = 'match';
        $qo->shufflestems = 0;
        $qo->questiontext = clean_param($xmlquestion->CONTENT, PARAM_RAW);
        $qo->questiontextformat = 0;
        $stems = $this->get_stems($xmlquestion);
        $choices = $this->get_choices($xmlquestion);
        $matches = $this->get_matches($xmlquestion);

        // Store stems in subquestions. This is used for displaying (stored as array).
        foreach ($stems as $stemid => $stemtext) {
            $qo->subquestions[$stemid] = array('text' => $stemtext, 'format' => FORMAT_HTML);
        }

        // Store choices in subanswers. This is used for the dropdown menu.
        foreach ($matches as $stemid => $match) {
            if (is_number($stemid)) {
                $qo->subanswers[$stemid] = $match[0]->choice;
                unset($choices[0][$match[0]->choice]);
            }
        }
        // Include any remaining wrong choices without a matching question.
        foreach ($choices[0] as $choice) {
            $qo->subquestions[] = array('text' => '', 'format' => FORMAT_HTML);
            $qo->subanswers[] = $choice;
        }

        // Get default mark and 'correct' feedback for the overall outcome.
        foreach ($xmlquestion->OUTCOME as $outcome) {
            $content = clean_param($outcome->CONTENT, PARAM_RAW);
            if ($outcome['ID'] == 'right') {
                $qo->defaultmark = clean_param($outcome['SCORE'], PARAM_TEXT);
                $qo->correctfeedback = array('text' => $content, 'format' => FORMAT_HTML);
            }
            if ($outcome['ID'] == 'wrong') {
                $qo->hint = array_fill(0, 2, array('text' => $content, 'format' => FORMAT_HTML));
            }
        }

        // Default feedback.
        if (empty($qo->correctfeedback['text'])) {
            $qo->correctfeedback = array('text' => get_string('correctfeedbackdefault', 'question'), 'format' => FORMAT_HTML);
        }
        $qo->partiallycorrectfeedback = array('text' => get_string('partiallycorrectfeedbackdefault', 'question'),
            'format' => FORMAT_HTML);
        $qo->incorrectfeedback = array('text' => get_string('incorrectfeedbackdefault', 'question'), 'format' => FORMAT_HTML);
        $qo->correctfeedbackformat = 0;
        $qo->partiallyfeedbackformat = 0;
        $qo->incorrectfeedbackformat = 0;

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
        $qo->single = ($xmlquestion->ANSWER['QTYPE'] == 'MC') ? 1 : 0;
        $qo->shuffleanswers = (isset($xmlquestion->ANSWER['SHUFFLE']) && $xmlquestion->ANSWER['SHUFFLE'] == 'YES') ? 1 : 0;

        // Answer count.
        $acount = 0;

        $ansconditiontext = clean_param($xmlquestion->OUTCOME[0]->CONDITION, PARAM_TEXT);

        /* It is possible that $ansConditionText will be: "0" or "1", but we want a
         * condition string such as: NOT "0" AND NOT "1" AND NOT "2" AND NOT "3" AND "4"
         */
        if (strlen($ansconditiontext) <= 3) {
            $outcomes = $this->create_combined_outcomes_object($xmlquestion);
            $ansconditiontext = $outcomes->ansstring;
            $feedback = $outcomes->feedback;
        } else {
            $feedback = $this->create_feedback_object($xmlquestion);
        }

        // Parse the logical answer string into an array of fractions.
        $anscondition = $this->parse_answer_condition($ansconditiontext, $qo->single);

        // Set some default values for feedback.
        $qo->correctfeedback = array('text' => get_string('correctfeedbackdefault', 'question'), 'format' => FORMAT_HTML);
        $qo->partiallycorrectfeedback = array('text' => get_string('partiallycorrectfeedbackdefault', 'question'),
            'format' => FORMAT_HTML);
        $qo->incorrectfeedback = array('text' => get_string('incorrectfeedbackdefault', 'question'), 'format' => FORMAT_HTML);

        // Loop the answers and set the correct fraction and default feedback for each.
        foreach ($xmlquestion->children() as $child) {
            if ($child->getName() == 'ANSWER') {
                foreach ($child->children() as $anschild) {
                    if ($anschild->getName() == 'CHOICE') {
                        $anstext = clean_param($anschild->CONTENT, PARAM_RAW);
                        $ansfraction = $anscondition[$acount];
                        if (is_array($feedback)) {
                            $ansfeedback = $feedback[$acount];
                        } else {
                            $ansfeedback = ($ansfraction > 0) ? $feedback->correct : $feedback->incorrect;
                        }

                        $qo->answer[$acount] = array('text' => $anstext, 'format' => FORMAT_HTML);
                        $qo->fraction[$acount] = $ansfraction;
                        $qo->feedback[$acount] = array('text' => $ansfeedback, 'format' => FORMAT_HTML);

                        ++$acount;
                    }
                }
                break;
            }
        }

        return $qo;
    }

    /**
     * Builds a logical string to parse the correct answers, and an array of feedback.
     * Used when the multichoice question has separate answers for the question.
     * @param SimpleXMLObject $xmlquestion The XML question object
     * @return stdClass An object containing the logical answer string and feedback
     */
    private function create_combined_outcomes_object($xmlquestion) {

        $ansstring = '';
        $feedback = array();
        $acount = 0;

        foreach ($xmlquestion->children() as $child) {
            if ($child->getName() == 'OUTCOME') {

                if ($child['SCORE'] == '0' || $child['ADD'] == '-1') {
                    $ansstring .= 'NOT ';
                }

                $ansstring .= '"' . $acount . '"' . ' ';
                $feedback[$acount] = clean_param($child->CONTENT, PARAM_RAW);
                ++$acount;
            }
        }

        $outcomes = new stdClass();
        $outcomes->ansstring = $ansstring;
        $outcomes->feedback = $feedback;

        return $outcomes;
    }

    /**
     * Creates an object containing feedback for correct and incorrect answers.
     * Used when there are just two types of feedback defined for the question.
     * @param SimpleXMLObject $xmlquestion The XML question object
     * @return stdClass An object containing the two sets of feedback
     */
    private function create_feedback_object($xmlquestion) {

        $feedback = new stdClass();

        foreach ($xmlquestion->children() as $child) {
            if ($child->getName() == 'OUTCOME') {
                if ($child['SCORE'] == '0' || $child['ADD'] == '-1') {
                    $feedback->incorrect = clean_param($child->CONTENT, PARAM_RAW);
                } else {
                    $feedback->correct = clean_param($child->CONTENT, PARAM_RAW);
                }
            }
        }

        return $feedback;
    }

    /**
     * Calculate the correct fraction for each answer
     * @param string logical string identifying the correct answer sequence
     * @param bool $singleonly Does this question accept just one answer?
     * @return array contains the fractions for each answer
     */
    private function parse_answer_condition($ansconditiontext, $singleonly = true) {

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
         * the value for that answer is set to 0 (in the case of single-response
         * questions); otherwise the answer value is set to answers worth (or an
         * equivalent penalty for wrong answers to multi-response questions).
         */
        $lastvaluenot = false;
        foreach ($anstextparts as $anstextpart) {
            if ($anstextpart == 'NOT') {
                $lastvaluenot = true;
            } else if (strpos($anstextpart, '"') !== false) {
                if ($lastvaluenot) {
                    $fractionarr[] = $singleonly ? 0 : -1;
                    $lastvaluenot = false;
                } else {
                    $fractionarr[] = 1;
                    $correctanscount++;
                }
            }
        }

        // TODO - Division by 0 on quesitons with ADD=1 BIOL WEEK 3.
        // Calculate how much each correct answer is actually worth.
        $correctansworth = 1 / $correctanscount;

        // For multi-response questions we need to penalise wrong answers.
        foreach ($fractionarr as $index => $fraction) {
            $fractionarr[$index] = $fraction * $correctansworth;
        }

        return $fractionarr;
    }

    /**
     * Import true or false question.
     * @param SimpleXML_Object Contains the question data in a SimpleXML_Object
     * @return object Question object
     */
    public function import_truefalse($xmlquestion) {
        // Get common parts.
        $qo = $this->import_headers($xmlquestion);

        // Header parts particular to true/false.
        $qo->qtype = 'truefalse';

        // Assume the first answer ID is for the true answer.
        $trueqid = 0;

        // The text value of the node either True or False.
        $answertext = strtolower(clean_param($xmlquestion->ANSWER->CHOICE[$trueqid]->CONTENT, PARAM_TEXT));

        // Populate the feedback array and set correct answer.
        if ($answertext == 'true') {
            $qo->feedbacktrue = array(
                'text' => clean_param($xmlquestion->OUTCOME[$trueqid]->CONTENT, PARAM_RAW),
                'format' => FORMAT_HTML
            );
            $qo->feedbackfalse = array(
                'text' => clean_param($xmlquestion->OUTCOME[$trueqid + 1]->CONTENT, PARAM_RAW),
                'format' => FORMAT_HTML
            );

            $qo->answer = $xmlquestion->OUTCOME[$trueqid]['SCORE'] == 1 ? true : false;
            $qo->correctanswer = $qo->answer;
        } else {
            $qo->feedbacktrue = array(
                'text' => clean_param($xmlquestion->OUTCOME[$trueqid + 1]->CONTENT, PARAM_RAW),
                'format' => FORMAT_HTML
            );
            $qo->feedbackfalse = array(
                'text' => clean_param($xmlquestion->OUTCOME[$trueqid]->CONTENT, PARAM_RAW),
                'format' => FORMAT_HTML
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
        $fibtype = $xmlquestion->OUTCOME[0]['ID'];
        $hasmultianswer = strpos(clean_param($xmlquestion->OUTCOME->CONDITION, PARAM_TEXT), 'AND') !== false;

        if ($hasmultianswer || $fibtype == '0') {
            $qo = $this->import_multi_answer_fib($xmlquestion, $qo);
        } else if (!$hasmultianswer && $fibtype != '0') {
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

        $nodetext = '';
        if (!empty($xmlquestion->CONTENT[0])) {
            $nodetext = clean_param($xmlquestion->CONTENT[0], PARAM_TEXT);
        }

        if (strlen($nodetext) > 0) {
            $qtext = clean_param($xmlquestion->CONTENT[0], PARAM_TEXT);
        } else {
            $qtext = $this->get_question_text($xmlquestion);
        }

        $ansqtext = clean_param($xmlquestion->ANSWER->CONTENT, PARAM_TEXT);

        if ($qo->name == 'Fill in Blanks question') {
            $qo->name = $qtext;
        }

        $qo->questiontext = $qtext;

        if (!empty($xmlquestion->OUTCOME[0])) {
            $ansconditiontext = clean_param($xmlquestion->OUTCOME[0]->CONDITION, PARAM_TEXT);
        }

        if (isset($ansconditiontext)) {
            if (strpos($ansconditiontext, '=') !== false) {
                $anstext = array(substr($ansconditiontext, 5));
            } else {
                $anstext = $this->break_logical_ans_str($ansconditiontext);
            }
            $feedback = $this->create_feedback_object($xmlquestion);
            $qo->hint = array_fill(0, 2, array('text' => $feedback->incorrect, 'format' => FORMAT_HTML));
            foreach ($anstext as $answer) {
                $qo->answer[] = trim($answer);
                $qo->feedback[] = array('text' => $feedback->correct, 'format' => FORMAT_HTML);
                $qo->fraction[] = 1;
            }
        } else {
            $this->error('Shortanswer questions with no correct answer are not'
                    . ' supported in moodle');
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

        $ansconditiontext = clean_param($xmlquestion->OUTCOME[0]->CONDITION, PARAM_TEXT);
        $correctansfeedback = clean_param($xmlquestion->OUTCOME[0]->CONTENT, PARAM_RAW);
        $incorrectansfeedback = clean_param($xmlquestion->OUTCOME[1]->CONTENT, PARAM_RAW);

        $qo->feedback[] = array('text' => $correctansfeedback, 'format' => FORMAT_HTML);
        $qo->feedback[] = array('text' => $incorrectansfeedback, 'format' => FORMAT_HTML);

        // How much is this answer worth for this question.
        $qo->fraction[] = 1;

        $qtext = $this->get_question_text($xmlquestion);

        $anstext = $this->break_logical_ans_str($ansconditiontext);

        /* Currently this will only match exact answers regardless of what the
         * exported settings were.  (case-insensitive)
         * Set the value text as our correct answer.
         */
        $qo->answer[] = $anstext[0];

        /* Try to overwrite the generic question name with something more descriptive
         * Not nessesary, but a lot of the test files had generic names, this
         * will replace that with part of the question text.
         */
        if ($qo->questiontext == 'Fill in Blanks question') {
            $qo->name = substr($qtext, 0, 30);
        }

        // Assign the question text.
        $qo->questiontext = $qtext;

        return $qo;
    }

    private function get_question_text($xmlquestion) {

        $qtext = '';

        // Loop the question object.
        foreach ($xmlquestion->children() as $child) {
            // We only care about the answer node.
            if ($child->getName() == 'ANSWER') {
                foreach ($child->children() as $anschild) {

                    // Append the text contained in this Answer->Content node.
                    if ($anschild->getName() == 'CONTENT') {
                        $qtext .= clean_param($anschild, PARAM_RAW_TRIMMED);
                    }

                    /* Insert a series of underscores instead of the answer.
                     * i.e. to show there is a blank that should go here.
                     */
                    if ($anschild->getName() == 'CHOICE') {
                        $qtext .= ' _____ ';
                    }
                }
            }
        }

        return $qtext;
    }

    private function break_logical_ans_str($logicalstr) {
        /*
         * The CONDITION text has 5 parts
         * NOT | Choices | Operation | Value | Boolean
         * They can be conditionals e.g. a node may look like
         * <CONDITION>"0" MATCHES NOCASE "reduction" OR "0" NEAR NOCASE "reduction"</CONDITION>
         * we only want to know the Value text to match against the users answer.
         */
        $anstext = array();

        // There may be more than one possible correct answer.
        $conditions = explode(' OR ', $logicalstr);
        foreach ($conditions as $condition) {
            $ansparts = explode(' ', $condition);

            if ($ansparts[1] == 'MATCHES') {
                // This 'should' be the correct answer.
                $anstext[] = str_replace('"', '', $ansparts[3]);
            }
        }

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
        $questiontext['format'] = FORMAT_HTML;

        $qo = qtype_multianswer_extract_question($questiontext);

        // Set values for the question.
        $qo->qtype = 'multianswer';
        $qo->course = $this->course;

        $qo->name = $multiansdata['qname'];
        $qo->questiontextformat = 0;
        $qo->questiontext = $qo->questiontext['text'];

        $qo->generalfeedback = '';
        $qo->generalfeedbackformat = FORMAT_HTML;
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

        $ignored = array('MATCHES', 'NOCASE', 'NEAR', 'AND', 'OR');

        // String to hold the question name, used by the calling function.
        $qname = '';

        // The question type.
        $qtype = QTYPE_SA;

        $ansconditiontext = '';

        foreach ($xmlquestion->children() as $child) {
            $nodename = $child->getName();
            if ($nodename == 'OUTCOME' && $child['ID'] != 'wrong' && $child['ID'] != 'Always happens') {
                $condition = clean_param($child->CONDITION, PARAM_TEXT);

                if (strpos($condition, '=')) {
                    $condition = substr($condition, 5);
                }

                $ansconditiontext .= $condition . ' ';
            }
        }

        // Question text.
        $qtext = '';

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
        $ansparts = explode(' ', $ansconditiontext);

        /* Loop used to iterate the logical condition answer string and build
         * up each of the cloze answer strings.
         */
        foreach ($ansparts as $anspart) {

            $text = str_replace('"', '', $anspart);

            // If we have a "MATCHES" in the answer condition string, we then ignore numerical answers.
            if (strpos($ansconditiontext, 'MATCHES') !== false) {
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
            $clozequestionformat .= '=' . $text;

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
            if ($child->getName() == 'ANSWER') {
                foreach ($child->children() as $anschild) {

                    // Append the text contained in this Answer->Content node.
                    if ($anschild->getName() == 'CONTENT') {
                        $qtext .= clean_param($anschild, PARAM_RAW);
                        $qname .= clean_param($anschild, PARAM_TEXT);
                    }

                    /* Append the parsed cloze string to mark the "blank"
                     * i.e. to show there is a blank that should go here
                     */
                    if ($anschild->getName() == 'CHOICE') {
                        $qtext .= ' {$clozeanswers[$answerindex]} ';
                        $qname .= ' {{$qanswers[$answerindex]}} ';
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
        $mdlquestiontype = '';
        switch ($strqtype) {
            case 'MR':
            case 'MC':
                $mdlquestiontype = 'multichoice';
                break;
            case 'MAT':
            case 'SEL':
                $mdlquestiontype = 'multianswer';
                break;
            case 'YN':
            case 'TF':
                $mdlquestiontype = 'truefalse';
                break;
            case 'FIB':
            case 'TM':
                $mdlquestiontype = 'shortanswer';
                break;
            case 'ESSAY':
                $mdlquestiontype = 'essay';
                break;
            case 'NUM':
                $mdlquestiontype = 'numerical';
                break;
            case 'MATCH':
            case 'RANK':
                $mdlquestiontype = 'match';
                break;
            default :
                $mdlquestiontype = $strqtype;
                break;
        }

        return $mdlquestiontype;
    }

}
