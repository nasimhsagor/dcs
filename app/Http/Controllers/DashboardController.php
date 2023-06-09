<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use App\Models\Admission;
use App\Models\Loans;
use App\Models\Branch;

ini_set('memory_limit', '3072M');
ini_set('max_execution_time', 1800);

class DashboardController extends Controller
{
    public function index(Request $request)
    {
        $db = config('database.db');
        $role_designation = session('role_designation');
        $request->session()->put('status_btn', '1');

        // date make
        $month = date('m');
        $day = date('d');
        $year = date('Y');

        return view('Dashboard');
    }

    public function fetchData(Request $request)
    {

        $today = date('Y-m-d');
        $from_date = date('Y-01-01');
        $status = $request->get('status') ?? null;
        $po = $request->get('po') ?? null;
        $erpstatus = $request->get('ErpStatus') ?? null;
        $getbranch = null;

        $query = DB::table('dcs.loans')
            ->where('reciverrole', '!=', '0')
            ->where('projectcode', session('projectcode'))
            ->whereDate('loans.time', '>=', $from_date)
            ->whereDate('loans.time', '<=', $today);

        
        if(!empty($request->input('division'))){
            $getbranch = $this->getBranch($request->input('division') ?? null, $request->input('region') ?? null, $request->input('area'), $request->input('branch') ?? null, $po);
            $branchId = $getbranch->pluck('branch_id')->toArray() ?? null;
            if(!empty($branchId)){
                $query->whereIn('branchcode', $branchId);
            }
        }

        if($erpstatus !=null && $status == null) $query->where('ErpStatus', $erpstatus);

        if($status !=null && $erpstatus !=null)
        {
            $query->where(function ($query) {
                    $query->where('status', 3)
                        ->orWhere('ErpStatus', 3);
                });
        }elseif($status !=null && $erpstatus ==null){ 
            $query->where('status', $status);
        }
            
        $data = $query->get();

        $counts = $this->allCount($request, $getbranch, $status, $erpstatus, $po);

        return [$data, $counts];
    }

    public function search(Request $request)
    {
        $status = $request->input('status') ?? null;
        $erpstatus = $request->input('ErpStatus') ?? null;
        $division = $request->input('division') ?? null;
        $region = $request->input('region') ?? null;
        $area = $request->input('area') ?? null;
        $branch = $request->input('branch') ?? null;
        $po = $request->input('po') ?? null;

        $getbranch = $this->getBranch($division, $region, $area, $branch, $po);

        $searchDataResult = $this->searchData($getbranch, $status, $erpstatus, $po);
        $counts = $this->allCount($request, $getbranch, $status, $erpstatus, $po);
        return response()->json([
            'searchDataResult'=>$searchDataResult,
            'counts'=>$counts
        ]);
    }

    public function searchData($getbranch, $status, $erpstatus, $po)
    {

        $today = date('Y-m-d');
        $from_date = date('Y-01-01');
        $getbranchIds = $getbranch->pluck('branch_id')->toArray();

        if ($po != null) {
            $data = DB::table('dcs.loans')
                ->where('reciverrole', '!=', '0')
                ->whereIn('branchcode', $getbranchIds)
                ->where('status', $status)
                ->where('assignedpo', $po)
                ->where('ErpStatus', $erpstatus)
                ->where('projectcode', session('projectcode'))
                ->whereDate('loans.time', '>=', $from_date)
                ->whereDate('loans.time', '<=', $today)
                ->get();

            return $data;
        } else {
            $data = DB::table('dcs.loans')
                ->where('reciverrole', '!=', '0')
                ->whereIn('branchcode', $getbranchIds)
                ->where('status', $status)
                ->where('ErpStatus', $erpstatus)
                ->where('projectcode', session('projectcode'))
                ->whereDate('loans.time', '>=', $from_date)
                ->whereDate('loans.time', '<=', $today)
                ->get();
        }
        
        return $data;
    }

public function allCount(Request $request, $getbranch=null, $status=null, $erpstatus =null, $po =null)
{
    $db = config('database.db');
    $role_designation = session('role_designation');
    $request->session()->put('status_btn', '1');
    // Get current date
    $today = date('Y-m-d');
    $from_date = date('Y-01-01');
    $showStartDate = date('d-M-Y', strtotime($from_date));
    $showEndDate = date('d-M-Y', strtotime($today));

    // Role wise data distribution
    $branch = null;
    $branchcodes = [];
    if ($role_designation == 'AM') 
    {
        $search = Branch::where(['area_id' => session('asid'),'program_id' => session('program_id')])->distinct('branch_id')->get();
    } 
    else if ($role_designation == 'RM') 
    {
        $search = Branch::where(['region_id' => session('asid'),'program_id' => session('program_id')])->distinct('area_id')->get();
    } 
    else if ($role_designation == 'DM') 
    {
        $search = Branch::where(['division_id' => session('asid'),'program_id' => session('program_id')])->distinct('region_id')->get();
    } 
    else if ($role_designation == 'HO' || $role_designation == 'PH') 
    {
        $search = DB::table('public.branch')->where('program_id', session('program_id'))->get();
    } 
    else 
    {
        return redirect()->back()->with('error', 'Data does not match.');
    }

    $branchcodes = $search->pluck('branch_id')->map(function ($branchId) 
    {
        return str_pad($branchId, 4, "0", STR_PAD_LEFT);
    })->toArray();

    if($getbranch != null) $getbranchIds = $getbranch->pluck('branch_id')->toArray();

    if (!empty($branchcodes)) {
        $pending_admission = Admission::where('projectcode', session('projectcode'))
            ->whereIn('branchcode', $branchcodes)
            ->where('Flag', 1)
            ->whereBetween('created_at', [$from_date, $today])
            ->where('reciverrole', '!=', '0')
            ->count();

        $pending_profileadmission = Admission::where('projectcode', session('projectcode'))
            ->where('Flag', 2)
            ->whereBetween('created_at', [$from_date, $today])
            ->where('reciverrole', '!=', '0')
            ->count();

        $pending_loan_query = Loans::where('projectcode', session('projectcode'))
            ->whereBetween('time', [$from_date, $today])
            ->where('reciverrole', '!=', '0');
        if(!empty($getbranchIds)){
            $pending_loan_query
                ->whereIn('branchcode', $getbranchIds)
                ->where('status', $status)
                ->where('ErpStatus', $erpstatus);
        }

        $pending_loan = $pending_loan_query->count();

        $all_pending_loan_query = Loans::where('reciverrole', '!=', '0')
            ->where('status', '1')
            ->where('projectcode', session('projectcode'))
            ->whereBetween('time', [$from_date, $today]);

        if(!empty($getbranchIds)){
            $all_pending_loan_query
                ->whereIn('branchcode', $getbranchIds);
        }
        
        $all_pending_loan = $all_pending_loan_query->count();

        $all_approve_loan_query = Loans::where('reciverrole', '!=', '0')
                ->where('projectcode', session('projectcode'))
                ->where('status','2')
                ->whereBetween('time', [$from_date, $today]);
                
        if(!empty($getbranchIds)){
            $all_approve_loan_query
                ->whereIn('branchcode', $getbranchIds);
        }

        $all_approve_loan = $all_approve_loan_query->count();

        $all_disbursement_loan_query = Loans::where('reciverrole', '!=', '0')
                ->where('ErpStatus', 1)
                ->where('projectcode', session('projectcode'))
                ->whereBetween('time', [$from_date, $today]);
            if(!empty($getbranchIds)){
                $all_disbursement_loan_query
                ->whereIn('branchcode', $getbranchIds);
            }
        $all_disbursement_loan = $all_disbursement_loan_query->count();


        $all_disburse_loan_query = Loans::where('reciverrole', '!=', '0')
                ->where('ErpStatus', 4)
                ->where('projectcode', session('projectcode'))
                ->whereBetween('time', [$from_date, $today]);
                
         if(!empty($getbranchIds)){
                $all_disburse_loan_query
                ->whereIn('branchcode', $getbranchIds);
            }
         $all_disburse_loan = $all_disburse_loan_query->count();

        $all_reject_loan_query = DB::table('dcs.loans')
                ->where('reciverrole', '!=', '0')
                ->where('projectcode', session('projectcode'))
                ->whereDate('loans.time', '>=', $from_date)
                ->whereDate('loans.time', '<=', $today)->where(function ($query) {
                    $query->where('status', 3)
                        ->orWhere('ErpStatus', 3);
                });

        if(!empty($getbranchIds)){
                $all_reject_loan_query
                ->whereIn('branchcode', $getbranchIds);
            }
         $all_reject_loan = $all_reject_loan_query->count();      

        $total_disbursed_amount_query = Loans::where('projectcode', session('projectcode'))
                ->where('reciverrole', '!=', '0')
                ->whereBetween('time', [$from_date, $today])
                ->where('ErpStatus', 4);

        if(!empty($getbranchIds)){
                $total_disbursed_amount_query
                ->whereIn('branchcode', $getbranchIds)
                ->where('status', $status);
                
            }
         $total_disbursed_amount = $total_disbursed_amount_query->count();


//all_pending_loan_data  all_approve_loan_data
        $jsondata = [
            'pendingadminssioncount' => $pending_admission,
            'pendingprofileadmission' => $pending_profileadmission,
            'pendingloan' => $pending_loan,
            'allpendingloan' => $all_pending_loan,
            'allapproveloan' => $all_approve_loan,
            'all_disbursement' => $all_disbursement_loan,
            'allrejectloan' => $all_reject_loan,
            'alldisburseloan' => $all_disburse_loan,
            'disburseamt' => $total_disbursed_amount,
            'fromdate' => $from_date,
            'today' => $today
        ];

        return $jsondata;
    }

    // return response()->json([]);
    return [];
}
    public function getRollWiseCounts(Request $request)
    {
        $today = date('Y-m-d');
        $from_date = date('Y-01-01');
        $projectcode = session('projectcode');
        $counts = [];
        $erpstatus = $request->input('erpStatus');
        $status = $request->input('roleStatus');
        $po = $request->input('po') ?? null;
        $getbranch = $this->getBranch($request->input('division') ?? null, $request->input('region') ?? null, $request->input('area'), $request->input('branch') ?? null, $po);
        $branchId = $getbranch->pluck('branch_id')->toArray() ?? null;
    
        // Pending Loans
        $am_query = Loans::where('reciverrole', '2')
        ->where('projectcode', $projectcode)
        ->whereBetween('time', [$from_date, $today]);
        
        if(!empty($request->input('division'))){
            if(!empty($branchId)){
                $am_query->whereIn('branchcode', $branchId);
            }
        }

        if($erpstatus !=null && $status == null) $am_query->where('ErpStatus', $erpstatus);

        if($status !=null && $erpstatus !=null)
        {
            $am_query->where(function($am_query){
                $am_query->where('status', 3)
                    ->orWhere('ErpStatus', 3);
            });
        }elseif($status !=null && $erpstatus ==null){ 
            $am_query->where('status', $status);
        }

        $counts['am_pending_loan'] = $am_query->count();
        unset($am_query);

        $bm_query = Loans::where('reciverrole', '1')
            ->where('projectcode', $projectcode)
            ->whereBetween('time', [$from_date, $today]);

        if(!empty($request->input('division'))){
            if(!empty($branchId)){
                $bm_query->whereIn('branchcode', $branchId);
            }
        }

        if($erpstatus !=null && $status == null) $bm_query->where('ErpStatus', $erpstatus);

        if($status !=null && $erpstatus !=null)
        {            
            $bm_query->where(function($bm_query){
                $bm_query->where('status', 3)
                    ->orWhere('ErpStatus', 3);
            });
        }elseif($status !=null && $erpstatus ==null){ 
            $bm_query->where('status', $status);
        }
        
        $counts['bm_pending_loan'] = $bm_query->count();
        unset($bm_query);

        $rm_query = Loans::where('reciverrole', '3')
            ->where('projectcode', $projectcode)
            ->whereBetween('time', [$from_date, $today]);

        if(!empty($request->input('division'))){
            if(!empty($branchId)){
                $rm_query->whereIn('branchcode', $branchId);
            }
        }

        if($erpstatus !=null && $status == null) $rm_query->where('ErpStatus', $erpstatus);

        if($status !=null && $erpstatus !=null)
        {
            $rm_query->where(function($rm_query){
                $rm_query->where('status', 3)
                    ->orWhere('ErpStatus', 3);
            });

        }elseif($status !=null && $erpstatus ==null){ 
            $rm_query->where('status', $status);
        }
        $counts['rm_pending_loan'] = $rm_query->count();
        unset($rm_query);

        $dm_query = Loans::where('reciverrole', '4')
            ->where('projectcode', $projectcode)
            ->whereBetween('time', [$from_date, $today]);
        
        if(!empty($request->input('division'))){
            if(!empty($branchId)){
                $dm_query->whereIn('branchcode', $branchId);
            }
        }

        if($erpstatus !=null && $status == null) $dm_query->where('ErpStatus', $erpstatus);

        if($status !=null && $erpstatus !=null)
        {
            $dm_query->where(function($dm_query){
                $dm_query->where('status', 3)
                    ->orWhere('ErpStatus', 3);
            });
        }elseif($status !=null && $erpstatus ==null){ 
            $dm_query->where('status', $status);
        }
        $counts['dm_pending_loan'] = $dm_query->count();
        unset($dm_query);

        return $counts;
    }
    public function getRollWiseData(Request $request)
    {
        $today = date('Y-m-d');
        $from_date = date('Y-01-01');
        $status = $request->input('roleStatus') ?? null;
        $po = $request->get('po') ?? null;
        $erpstatus = $request->input('erpStatus')?? null;
        $reciverrole = $request->input('reciverrole');
        $getbranch = null;

        $query = DB::table('dcs.loans')
            ->where('reciverrole', $reciverrole)
            ->where('projectcode', session('projectcode'))
            ->whereDate('loans.time', '>=', $from_date)
            ->whereDate('loans.time', '<=', $today);

        if(!empty($request->input('division'))){
            $getbranch = $this->getBranch($request->input('division') ?? null, $request->input('region') ?? null, $request->input('area'), $request->input('branch') ?? null, $po);
            $branchId = $getbranch->pluck('branch_id')->toArray() ?? null;
            if(!empty($branchId)){
                $query->whereIn('branchcode', $branchId);
            }
        }

        if($erpstatus !=null && $status == null) $query->where('ErpStatus', $erpstatus);

        if($status !=null && $erpstatus !=null)
        {
            $query->where(function($query){
                $query->where('status', 3)
                    ->orWhere('ErpStatus', 3);
            });
        }elseif($status !=null && $erpstatus ==null){ 
            $query->where('status', $status);
        }
            
        $data = $query->get();

        return $data;
    }

    public function GetDivisionData(Request $request)
    {
        $programId = $request->get('program_id');
        $division_list = DB::Table('branch')
            ->select('division_id', 'division_name')
            ->where('program_id', $programId)
            ->distinct('division_id')->get();
        return $division_list;
    }
    public function GetRegionData(Request $request)
    {
        $divisionId = $request->get('division_id');
        $region_list = DB::Table('branch')
            ->select('region_id', 'region_name')
            ->where('division_id', $divisionId)
            ->distinct('region_id')->get();
        return $region_list;
    }
    public function GetAreaData(Request $request)
    {
        $regionId = $request->get('region_id');
        $area_list = DB::Table('branch')
            ->select('area_id', 'area_name')
            ->where('region_id', $regionId)
            ->distinct('area_id')->get();
        return $area_list;
    }
    public function GetBranchData(Request $request)
    {
        $areaId = $request->get('area_id');
        $branch_list = DB::Table('branch')
            ->select('branch_id', 'branch_name')
            ->where('area_id', $areaId)
            ->distinct('branch_id')->get();
        return $branch_list;
    }
    public function GetProgramOrganizerData(Request $request)
    {
        $BranchCode = $request->get('branchcode');
        $pos_list = DB::Table('dcs.polist')
            ->select('cono', 'coname')
            ->where('branchcode', $BranchCode)->get();

        return $pos_list;
    }
    
    public static function getBranch($division = null, $region = null, $area = null, $branch = null, $po = null){
        $query = DB::Table('branch')
                ->select('branch_id')
                ->where('program_id', 1)
                ->distinct('branch_id');

        if($division !== null)
        {
            $query->where('division_id', $division);
        }
        if($region !== null)
        {
            $query->where('region_id', $region);
        }
        if($area !== null)
        {
            $query->where('area_id', $area);
        }
        if($branch !== null)
        {
            $query->where('branch_id', $branch);
        }
        if($po !== null)
        {
            $query->where('division', $po);
        }

        return $query->get();

    }
}
