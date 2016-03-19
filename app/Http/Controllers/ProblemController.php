<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Response;
use Illuminate\Support\Facades\View;
use Illuminate\Support\Facades\Redirect;
use Illuminate\Support\Facades\Validator;

use App\User;
use App\Problem;
use App\Submission;
use App\Testcase;
use Mockery\Exception;
use Storage;
use App\ContestProblem;
use App\ContestUser;
use App\Contest;
use Symfony\Component\VarDumper\Caster\ExceptionCaster;

class ProblemController extends Controller
{
    public function getProblem(Request $request)
    {
        $page_id = $request->session()->get('page_id');
        if($page_id == NULL) $page_id = 1;
        return Redirect::to("/problem/p/".$page_id);
    }

    public function getProblemByID(Request $request, $problem_id)
    {
        $problemObj = Problem::where("problem_id", $problem_id)->first();
        if($problemObj == NULL)
        {

        }
        $roleController = new RoleController();
        if(!$roleController->is("admin") && $problemObj->visibility_locks != 0)
        {
            abort(404);
        }
        $jsonObj = json_decode($problemObj->description);
        $data['problem'] = $problemObj;
        if($jsonObj != NULL)
        {
            foreach ($jsonObj as $key => $val)
            {
                $data['problem']->$key = $val;
            }
        }
        $data['problem']->totalSubmissionCount = Submission::where('pid', $problem_id)->count();
        $data['problem']->acSubmissionCount = Submission::where([
            'pid' => $problem_id,
            'result' => "Accepted"
        ])->count();
        return View::make("problem.index", $data);
    }

    public function getProblemListByPageID(Request $request, $page_id)
    {
        $problemPerPage = 20;

        /**  Remove the customize pagination function
        if($request->method() == "GET")
        {
            if(($problemPerPage = $request->session()->get('problem_per_page')) == NULL)
                $problemPerPage = 10;
        }
        elseif($request->method() == "POST")
        {
            $input = $request->input();
            if(($problemPerPage = $input['problem_per_page']) == NULL)
                $problemPerPage = 10;
            else
                $request->session()->put('problem_per_page', $problemPerPage);
        }
         **/
        $data = [];
        $data = Problem::getProblemItemsInPage($problemPerPage, $page_id);
        $request->session()->put('page_id', $page_id);
        return View::make('problem.list', $data);
    }

    public function showProblemDashboard(Request $request)
    {
        return Redirect::to('/dashboard/problem/p/1');
    }

    public function showProblemDashboardByPageID(Request $request, $page_id)
    {
        $problemPerPage = 20;
        $data = [];

        $data = Problem::getProblemItemsInPage($problemPerPage, $page_id);
        if(session('status'))
        {
            $data['status'] = session('status');
        }

        return View::make('problem.manage', $data);
    }

    public function setProblem(Request $request, $problem_id)
    {
        $data[] = NULL;
        $data['infos'] = [];
        $data['errors'] = [];
        $problemObj = Problem::where('problem_id', $problem_id)->first();
        $testcaseObj = Testcase::where('pid', $problem_id)->first();
        if($problemObj == NULL)
        {
            return Redirect::to('/dashboard/problem/');
        }
        if($testcaseObj != NULL)
        {
            $testcaseObj = Testcase::where('pid', $problem_id)->get();
        }
        $data['testcases'] = $testcaseObj;
        if($request->method() == "POST")
        {
            /*
             * POST means update , Update the problem Info
             */
            $updateProblemData = $request->input();

            /*
             * Description is stored in json format
             * encode it and store it
             * And do not store limitation info in the description
             */
            unset($updateProblemData['_token']);
            foreach($updateProblemData as $key => $val)
            {
                if(strpos($key, "limit"))
                {
                    continue;
                }
                $jsonObj[$key] = $val;
            }
            unset($updateProblemData['input']);
            unset($updateProblemData['output']);
            unset($updateProblemData['sample_input']);
            unset($updateProblemData['sample_output']);
            unset($updateProblemData['source']);
            $updateProblemData['description'] = json_encode($jsonObj);
            //var_dump($input);
            Problem::where('problem_id', $problem_id)->update($updateProblemData);
            /*
             * Check if testcase files are changed
             */
            $uploadInput = $request->file('input_file');
            $uploadOutput = $request->file('output_file');
            var_dump($uploadInput[0]);
            if(count($uploadInput) == count($uploadOutput) && $uploadInput[0] && $uploadOutput[0])
            {
                Testcase::where('pid', $problem_id)->delete();
                for($i = 0; $i < count($uploadInput); $i++)
                {
                    $updateTestcaseData['rank'] = $i + 1;
                    $updateTestcaseData['input_file_name'] = $problem_id . "-" . time() . "-" . $uploadInput[$i]->getClientOriginalName();
                    $updateTestcaseData['output_file_name'] = $problem_id . "-". time() . "-" . $uploadOutput[$i]->getClientOriginalName();
                    $updateTestcaseData['pid'] = $problem_id;
                    if($uploadInput[$i]->isValid() && $uploadOutput[$i]->isValid())
                    {
                        var_dump($updateTestcaseData);
                        $inputContent = file_get_contents($uploadInput[$i]->getRealPath());
                        $outputContent = file_get_contents($uploadOutput[$i]->getRealPath());
                        $updateTestcaseData['md5sum_input'] = md5($inputContent);
                        $updateTestcaseData['md5sum_output'] = md5($outputContent);
                        Storage::put(
                            'testdata/'. $updateTestcaseData['input_file_name'],
                            $inputContent
                        );
                        Storage::put(
                            'testdata/'. $updateTestcaseData['output_file_name'],
                            $outputContent
                        );
                        Testcase::create($updateTestcaseData);
                    }
                    else
                    {
                        array_push($data['errors'], "File Corrupted During Upload");
                        break;
                    }
                }
                array_push($data['infos'], "Update Testcase Data!");
            }
            array_push($data['infos'], "Update Problem Info!");
            // Flash the status info into session
            return Redirect::to($request->server('REQUEST_URI'))->with('status', $data);
        }
        else
        {
            $status = session('status');
            /*
             * Previously we save the changes to the problem
             */
            if($status)
            {
                foreach($status as $key => $val)
                {
                    $data[$key] = $val;
                }
            }
            $jsonObj = json_decode($problemObj->description);
            $data['problem'] = $problemObj;
            if($jsonObj != NULL)
            {
                foreach ($jsonObj as $key => $val)
                {
                    $data['problem']->$key = $val;
                }
            }
            else
            {
                $data['problem'] = $problemObj;
            }
            return View::make('problem.set', $data);
        }
    }

    public function delProblem(Request $request, $problem_id)
    {
        Problem::where('problem_id', $problem_id)->delete();
        Testcase::where('pid', $problem_id)->delete();
        $status = "Successfully Delete Problem $problem_id";
        return Redirect::to('/dashboard/problem/')->with('status', $status);
    }

    public function addProblem(Request $request)
    {
        $data[] = NULL;
        $data['infos'] = [];
        $data['errors'] = [];
        $data['testcases'] = [];
        if($request->method() == "POST")
        {
            /*
             * POST means add , Add the problem Info
             */
            $problemObj = new Problem();
            $testcaseObj = new Testcase();
            $problemObj->author_id = $request->session()->get('uid');
            $problemObj->save();
            $testcaseObj->save();
            $problem_id=Problem::max('problem_id');
            $data['testcases'] = $testcaseObj;
            $updateProblemData = $request->input();

            /*
             * Description is stored in json format
             * encode it and store it
             * And do not store limitation info in the description
             */
            unset($updateProblemData['_token']);
            foreach($updateProblemData as $key => $val)
            {
                if(strpos($key, "limit"))
                {
                    continue;
                }
                $jsonObj[$key] = $val;
            }
            unset($updateProblemData['input']);
            unset($updateProblemData['output']);
            unset($updateProblemData['sample_input']);
            unset($updateProblemData['sample_output']);
            unset($updateProblemData['source']);
            $updateProblemData['description'] = json_encode($jsonObj);
            //var_dump($input);
            Problem::where('problem_id', $problem_id)->update($updateProblemData);
            /*
             * Check if testcase files are changed
             */
            $uploadInput = $request->file('input_file');
            $uploadOutput = $request->file('output_file');
            var_dump($uploadInput[0]);
            if(count($uploadInput) == count($uploadOutput) && $uploadInput[0] && $uploadOutput[0])
            {
                Testcase::where('pid', $problem_id)->delete();
                for($i = 0; $i < count($uploadInput); $i++)
                {
                    $updateTestcaseData['rank'] = $i + 1;
                    $updateTestcaseData['input_file_name'] = $problem_id . "-" . time() . "-" . $uploadInput[$i]->getClientOriginalName();
                    $updateTestcaseData['output_file_name'] = $problem_id . "-". time() . "-" . $uploadOutput[$i]->getClientOriginalName();
                    $updateTestcaseData['pid'] = $problem_id;
                    if($uploadInput[$i]->isValid() && $uploadOutput[$i]->isValid())
                    {
                        var_dump($updateTestcaseData);
                        $inputContent = file_get_contents($uploadInput[$i]->getRealPath());
                        $outputContent = file_get_contents($uploadOutput[$i]->getRealPath());
                        $updateTestcaseData['md5sum_input'] = md5($inputContent);
                        $updateTestcaseData['md5sum_output'] = md5($outputContent);
                        Storage::put(
                            'testdata/'. $updateTestcaseData['input_file_name'],
                            $inputContent
                        );
                        Storage::put(
                            'testdata/'. $updateTestcaseData['output_file_name'],
                            $outputContent
                        );
                        Testcase::create($updateTestcaseData);
                    }
                    else
                    {
                        array_push($data['errors'], "File Corrupted During Upload");
                        break;
                    }
                }
                array_push($data['infos'], "Update Testcase Data!");
            }
            array_push($data['infos'], "Update Problem Info!");
            // Flash the status info into session
            return Redirect::route('dashboard.problem');
        }
        else
        {
            return View::make('problem.add',$data);
        }
    }

    public function getContestProblemByContestProblemID(Request $request, $contest_id, $problem_id)
    {
        $data = [];
        $uid = $request->session()->get('uid');
        $contestObj = Contest::where('contest_id', $contest_id)->first();
	$userObj = User::where('uid', $uid)->first();
	if($userObj)
            $username = $userObj->username;
        else
	    $username = "";
        if(time() < strtotime($contestObj->begin_time))
        {
            //Admin Special Privilege
            if(!($request->session()->get('uid') && $request->session()->get('uid') <= 2))
                return Redirect::to("/contest/$contest_id");
        }
        if($contestObj->contest_type == 1)
        {
            //if(!($request->session()->get('uid') && $request->session()->get('uid') <= 2))
            if(!RoleController::is('admin'))
            {
                $contestUserObj = ContestUser::where('username', $username)->first();
                //var_dump($contestUserObj);
                if($contestUserObj == NULL)
                    return Redirect::to('/contest/p/1');
            }
        }
        $contestProblemObj = ContestProblem::where([
            "contest_id" => $contest_id,
            "contest_problem_id" => $problem_id,
        ])->first();

        $realProblemID = $contestProblemObj->problem_id;

        $problemObj = Problem::where('problem_id', $realProblemID)->first();

        if($problemObj == NULL)
        {

        }
        $jsonObj = json_decode($problemObj->description);
        $data['problem'] = $problemObj;
        $data['problem']->problem_id = $problem_id;
        if($jsonObj != NULL)
        {
            foreach ($jsonObj as $key => $val)
            {
                $data['problem']->$key = $val;
            }
        }
        //var_dump($data['problem']);
        $data['problem']->title = $contestProblemObj->problem_title;
        $data['isContest'] = true;
        $data['contest'] = $contestObj;
        $data['problem']->totalSubmissionCount = Submission::where([
            'pid' => $realProblemID,
            'cid' => $contest_id,
        ])->count();
        $data['problem']->acSubmissionCount = Submission::where([
            'pid' => $realProblemID,
            'cid' => $contest_id,
            'result' => "Accepted"
        ])->count();
        return View::make("problem.index", $data);
    }

    /*
     * @function importProblem
     * @input $request
     *
     * @return Redirect
     * @description import problem from given xml file
     *              if no file selected redirect back with err
     *              else parse xml and add data into database
     *              and storage
     */
    public function importProblem(Request $request)
    {
        $this->validate($request,[
            "xml" => "required"
        ]);

        /* For file that is too big , first store it in memory */
        $dataStr = file_get_contents($request->file('xml')->getRealPath());

        /* Use SimpleXML to import Data from XML File */
        $xmlObj = simplexml_load_string($dataStr);
        foreach($xmlObj->item as $importData)
        {
            $problemObj = new Problem();
            $testCaseObj = new Testcase();

            /* Fetch The basic info of the problem */
            $problemObj->title = $importData->title->__toString();
            $problemObj->visibility_locks = 0;
            $problemObj->description = $importData->description->__toString();
            $problemObj->time_limit = $importData->time_limit * 1;
            $problemObj->mem_limit = $importData->memory_limit * 1024;
            $problemObj->output_limit = 10000000;
            $problemObj->difficulty = 0;
            $problemObj->input = $importData->input->__toString();
            $problemObj->output = $importData->output->__toString();
            $problemObj->sample_input = $importData->sample_input->__toString();
            $problemObj->sample_output = $importData->sample_output->__toString();
            $problemObj->source = "NEUOJ-old";
            $problemObj->author_id = session('uid');
            $jsonData = json_encode($problemObj);
            $problemObj->description = $jsonData;

            /* Unset fields that don't exist the database */
            unset($problemObj->input);
            unset($problemObj->output);
            unset($problemObj->sample_input);
            unset($problemObj->sample_output);
            unset($problemObj->source);
            $problemObj->save();

            /* Fetch The testdata for the problem */
            $input_data = $importData->test_input;
            $output_data = $importData->test_output;
            $testCaseObj->pid = $problemObj->id;
            $testCaseObj->rank = 1;
            $testCaseObj->input_file_name = $testCaseObj->pid . "-" . time() . "-" ."in";
            $testCaseObj->output_file_name = $testCaseObj->pid . "-" . time() . "-" ."out";
            $testCaseObj->md5sum_input = md5($input_data);
            $testCaseObj->md5sum_output = md5($output_data);
            $testCaseObj->save();
            Storage::put('testdata/'. $testCaseObj->input_file_name, $input_data);
            Storage::put('testdata/'. $testCaseObj->output_file_name, $output_data);
        }
        return Redirect::to('/dashboard/problem/');
    }

}
