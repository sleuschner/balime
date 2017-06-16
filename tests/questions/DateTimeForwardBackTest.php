<?php

namespace ls\tests;

use PHPUnit\Framework\TestCase;

/**
 * @since 2017-06-13
 */
class DateTimeForwardBackTest extends \PHPUnit_Framework_TestCase
{
    /**
     * @var TestHelper
     */
    protected static $testHelper = null;

    /**
     * @var int
     */
    public static $surveyId = null;

    /**
     * Import survey in tests/surveys/.
     */
    public static function setupBeforeClass()
    {
        \Yii::import('application.helpers.common_helper', true);
        \Yii::import('application.helpers.replacements_helper', true);
        \Yii::import('application.helpers.surveytranslator_helper', true);
        \Yii::import('application.helpers.admin.import_helper', true);
        \Yii::import('application.helpers.expressions.em_manager_helper', true);
        \Yii::import('application.helpers.expressions.em_manager_helper', true);
        \Yii::import('application.helpers.qanda_helper', true);
        \Yii::app()->loadHelper('admin/activate');

        \Yii::app()->session['loginID'] = 1;

        self::$testHelper = new TestHelper();

        $surveyFile = __DIR__ . '/../data/surveys/limesurvey_survey_917744.lss';
        if (!file_exists($surveyFile)) {
            die('Fatal error: found no survey file');
        }

        $translateLinksFields = false;
        $newSurveyName = null;
        $result = importSurveyFile(
            $surveyFile,
            $translateLinksFields,
            $newSurveyName,
            null
        );
        if ($result) {
            self::$surveyId = $result['newsid'];
        } else {
            die('Fatal error: Could not import survey');
        }
    }

    /**
     * Destroy what had been imported.
     */
    public static function teardownAfterClass()
    {
        $result = \Survey::model()->deleteSurvey(self::$surveyId, true);
        if (!$result) {
            die('Fatal error: Could not clean up survey ' . self::$surveyId);
        }
    }

    /**
     * q1 is hidden question with default answer "now".
     * @group q01
     */
    public function testQ1()
    {
        list($question, $group, $sgqa) = self::$testHelper->getSgqa('G1Q00001', self::$surveyId);
        $surveyMode = 'group';
        $LEMdebugLevel = 0;
        $survey = \Survey::model()->findByPk(self::$surveyId);

        $survey->anonymized = '';
        $survey->datestamp = '';
        $survey->ipaddr = '';
        $survey->refurl = '';
        $survey->savetimings = '';
        $survey->save();
        \Survey::model()->resetCache();  // Make sure the saved values will be picked up

        $result = \activateSurvey(self::$surveyId);

        // Must fetch this AFTER survey is activated.
        $surveyOptions = self::$testHelper->getSurveyOptions(self::$surveyId);

        $this->assertEquals(['status' => 'OK'], $result, 'Activate survey is OK');

        \Yii::app()->setConfig('surveyID', self::$surveyId);
        \Yii::app()->setController(new \CController('dummyid'));
        buildsurveysession(self::$surveyId);
        $result = \LimeExpressionManager::StartSurvey(
            self::$surveyId,
            $surveyMode,
            $surveyOptions,
            false,
            $LEMdebugLevel
        );
        $this->assertEquals(
            [
                'hasNext' => 1,
                'hasPrevious' => null
            ],
            $result
        );

        $qid = $question->qid;
        $gseq = 0;
        $_POST['relevance' . $qid] = 1;
        $_POST['relevanceG' . $gseq] = 1;
        $_POST['lastgroup'] = self::$surveyId . 'X' . $group->gid;
        $_POST['movenext'] = 'movenext';
        $_POST['thisstep'] = 1;
        $_POST['sid'] = self::$surveyId;
        $_POST[$sgqa] = '10:00';
        $_SESSION['survey_' . self::$surveyId]['maxstep'] = 2;
        $_SESSION['survey_' . self::$surveyId]['step'] = 1;

        $moveResult = \LimeExpressionManager::NavigateForwards();
        $result = \LimeExpressionManager::ProcessCurrentResponses();
        $this->assertEquals($result[$sgqa]['value'], '1970-01-01 10:00');

        $moveResult = \LimeExpressionManager::NavigateForwards();
        // Result is empty dummy text question.
        \LimeExpressionManager::ProcessCurrentResponses();

        // Check answer in database.
        $query = 'SELECT * FROM lime_survey_' . self::$surveyId;
        $result = \Yii::app()->db->createCommand($query)->queryAll();
        $this->assertEquals($result[0][$sgqa], '1970-01-01 10:00:00', 'Answer in database is 10:00');

        // Check result from qanda.
        $qanda = \retrieveAnswers(
            $_SESSION['survey_' . self::$surveyId]['fieldarray'][0],
            self::$surveyId
        );
        $this->assertEquals(false, strpos($qanda[0][1], "val('11:00')"), 'No 11:00 value from qanda');
        $this->assertNotEquals(false, strpos($qanda[0][1], "val('10:00')"), 'One 10:00 value from qanda');

        $surveyId = self::$surveyId;
        $date     = date('YmdHis');

        // Deactivate survey.
        $oldSurveyTableName = \Yii::app()->db->tablePrefix."survey_{$surveyId}";
        $newSurveyTableName = \Yii::app()->db->tablePrefix."old_survey_{$surveyId}_{$date}";
        \Yii::app()->db->createCommand()->renameTable($oldSurveyTableName, $newSurveyTableName);
        $survey = \Survey::model()->findByPk($surveyId);
        $survey->active = 'N';
        $survey->save();
    }
}
