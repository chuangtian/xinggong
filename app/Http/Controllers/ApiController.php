<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use ERC20\ERC20;
use EthereumRPC\EthereumRPC;
use Illuminate\Support\Facades\Validator;
use App\Jsonrpc\Eth;
use Exception;
use Illuminate\Support\Facades\DB;
use App\Models\TokenBalance;
use Illuminate\Support\Facades\Session;
use Illuminate\Support\Facades\Redis;


class ApiController extends Controller
{
    public function send(Request $request){
        $validator = Validator::make($request->all(), [
            'from' => 'required',
            'password' => 'required',
            'to' => 'required',
            'amount' => 'required',
            'key' => 'required',
            'contract' => 'required',
            'decimals' => 'required',
        ]);
        //判断参数不为空
        if ($validator->fails()) {
            $data['code']=402;
            $data['message']='error';
            return $data;
        }
        //dd(implode(',',$request->all()));
//        $a=implode(',',$request->all());
//        $info=DB::table('accounts')->insert(array('address'=>$a,'platformName'=>'data'));
        $from_data['from']=$request->from;
        $from_data['password']=$request->password;
        $from_data['to']=$request->to;
        $from_data['amount']=$request->amount;
        $from_data['key']=$request->key;
        $from_data['contract']=$request->contract;
        $from_data['decimals']=$request->decimals;
        $from_data['addtime']=date('Y-m-d H:i:s');
        DB::table('token_from_data')->insert($from_data);
        //判断区块是否落后
        $blockNumberInfo=$this->blockNumber();
        if($blockNumberInfo!=1){
            $data['code']=400;
            $data['message']='区块落后';
            return $data;
        }
        //判断key
        $key=$request->input('key');
        if($key!=='NIuse1XCyOvX$5Y'){
            $data['code']=402;
            $data['message']='error';
            return $data;
        }
        try {
            $data['from']=$request->input('from');
            $data['password']=$request->input('password');
            $data['to']=$request->input('to');
            $data['amount']=$request->input('amount');
            //jsonrpc
            //$gethrpc=new Eth('http://141.193.156.178:2406');//测试网络
            $gethrpc=new Eth('http://127.0.0.1:2406');//本机
            $balance=$gethrpc->eth_getBalance($data['from'],'latest');
            $balance=hexdec($balance["result"]);
            if($balance<160000000000000){
                $data['code']=402;
                $data['message']='以太坊不足';
                return $data;
            }
            //获取价格
            $ethGasPrice=$gethrpc->eth_gasPrice();
            $gasPrice3 = hexdec($ethGasPrice['result']);
            //erc20 链接钱包
            //$geth = new EthereumRPC("141.193.156.178",2406);//测试网络
            $geth = new EthereumRPC("127.0.0.1",2406);//本机
            $erc20 = new ERC20($geth);
            //合同
            $contract = $request->input('contract'); // ERC20 contract address
            $payer = $data['from']; // Sender's Ethereum account
            $payee = $data['to']; // Recipient's Ethereum account
            $amount=$data['amount'];
            $decimals=$request->input('decimals');
            $ling='1';
            for ($i=0;$i<$decimals;$i++){
                $ling.='0';
            }

            //计算转出金额
            $amount= bcmul($amount, $ling);
            //验证余额是否充足
            $dalance_data=$this->getBalance($payer,$contract);
            //dd($dalance_data,$amount);
            if($dalance_data['code']==200){
                if($amount>$dalance_data['balance']){
                    $data['code']=402;
                    $data['message']='Token 余额不足';
                    return $data;
                }else{
                    DB::beginTransaction(); //开启事务
                    $modelTokenBalance=new TokenBalance();
                    $up_balance=bcsub($dalance_data['balance'],$amount,0);
                    $upinfo=$modelTokenBalance->updateBalance2($data['from'],$up_balance);
                    if($upinfo){
                        DB::commit();  //提交
                    }else{
                        DB::rollback();  //回滚
                    }

                }
            }
            $token = $erc20->token($contract);
            $data["data"] = $token->encodedTransferData($payee,$amount);
            $gasPrice2= bcdiv(bcmul($gasPrice3,'2',18), "1000000000000000000",18);
            $transaction = $geth->personal()->transaction($payer, $contract)->gas(80000,$gasPrice2)->amount("0")->data($data["data"]); // Our encoded ERC20 token transfer data from previous step
            //dd($transaction,$data);
            $res = $transaction->send($data['password']); // Replace "secret" with actual passphrase of SENDER's ethereum
            $r_data['code']=200;
            $r_data['message']=$res;
            return $r_data;
        } catch (\Exception $exception) {
            //恢复金额
            $amount=$request->input('amount');
            $decimals=$request->input('decimals');
            $ling='1';
            for ($i=0;$i<$decimals;$i++){
                $ling.='0';
            }
            //计算恢复金额
            $amount= bcmul($amount, $ling);
            $modelTokenBalance=new TokenBalance();
            $dalance_data=$this->getBalance($request->input('from'),$request->input('contract'));
            $up_balance=bcadd($dalance_data['balance'],$amount,0);
            $upinfo=$modelTokenBalance->updateBalance2($request->input('from'),$up_balance);
            //dd($exception);
            $data['code']=405;
            $data['message']='error';
            return $data;
        }

    }


    public function wsend(Request $request){
        $validator = Validator::make($request->all(), [
            'from' => 'required',
            //'password' => 'required',
            'to' => 'required',
            'amount' => 'required',
            'key' => 'required',
            'contract' => 'required',
            'decimals' => 'required',
            'wid' => 'required',
        ]);
//        $ip=$_SERVER["REMOTE_ADDR"];
//        if($ip!='103.84.86.162' and $ip!='103.84.86.163'){
//            $data['code']=402;
//            $data['message']='拒绝访问';
//            return $data;
//        }

        $errors = json_decode(json_encode($validator->errors()), true);
        //判断参数不为空
        if ($validator->fails()) {
            $data['code']=402;
            $data['message']=$errors;
            return $data;
        }
        //dd(implode(',',$request->all()));
//        $a=implode(',',$request->all());
//        $info=DB::table('accounts')->insert(array('address'=>$a,'platformName'=>'data'));
        $from_data['from']=$request->from;
        $from_data['password']='PrMr!^hgjlpl3W^Y';
        $from_data['to']=$request->to;
        $from_data['amount']=$request->amount;
        $from_data['key']=$request->key;
        $from_data['contract']=$request->contract;
        $from_data['decimals']=$request->decimals;
        $from_data['addtime']=date('Y-m-d H:i:s');
        $from_data['wid']=$request->wid;
        //$from_data['nonce']=$this->nonce($request->from);
        $from_data['nonce']=0;
        if($request->wid!=0){
            $info=DB::table('token_w_from_data')->where('wid',$request->wid)->orderBy('id', 'desc')->first();
            //dd($info,$request->wid);
            if($info and $info->hash!='' ){
                $data['code']=201;
                $data['message']=$info->hash;
                return $data;
            }

        }else{
            $data['code']=402;
            $data['message']='wid不能为0';
            return $data;
        }
        $id=DB::table('token_w_from_data')->insertGetId($from_data);
        //判断区块是否落后
        $blockNumberInfo=$this->blockNumber();
        if($blockNumberInfo!=1){
            $data['code']=400;
            $data['message']='区块落后';
            return $data;
        }
        //判断key
        $key=$request->input('key');
        $hash = md5($from_data['wid'].'PrMr!^hgjlpl3W^Y'.'NIuse1XCyOvX$5Y'.'1dhekb8vxVL6n1s6'.$request->amount.$request->to);
        if($key!=$hash){
            $data['code']=402;
            $data['message']='Key error';
            return $data;
        }

        try {
            $data['from']=$request->input('from');
            $data['password']='l4xbuh%DjehrGgqW';

            $data['to']=$request->input('to');
            $data['amount']=$request->input('amount');
            //jsonrpc
            //$gethrpc=new Eth('http://141.193.156.178:2406');//测试网络
            $gethrpc=new Eth('http://127.0.0.1:2406');//本机
            $balance=$gethrpc->eth_getBalance($data['from'],'latest');
            $balance=hexdec($balance["result"]);
            if($balance<160000000000000){
                $data['code']=402;
                $data['message']='以太坊不足';
                return $data;
            }
            if (!(preg_match('/^(0x)?[0-9a-fA-F]{40}$/', $data['to']))) {
                $data['code']=403;
                $data['message']='Address error';
                return $data;
            }

            //获取价格
            $ethGasPrice=$gethrpc->eth_gasPrice();
            $gasPrice3 = hexdec($ethGasPrice['result']);
            //erc20 链接钱包
            //$geth = new EthereumRPC("141.193.156.178",2406);//测试网络
            $geth = new EthereumRPC("127.0.0.1",2406);//本机
            $erc20 = new ERC20($geth);
            //合同
            $contract = $request->input('contract'); // ERC20 contract address
            $payer = $data['from']; // Sender's Ethereum account
            $payee = $data['to']; // Recipient's Ethereum account
            $amount=$data['amount'];
            $decimals=$request->input('decimals');
            $ling='1';
            for ($i=0;$i<$decimals;$i++){
                $ling.='0';
            }

            //计算转出金额
            $amount= bcmul($amount, $ling);
            //验证余额是否充足
            $dalance_data=$this->getBalance($payer,$contract);
            //dd($dalance_data,$amount);
            if($dalance_data['code']==200){
                if($amount>$dalance_data['balance']){
                    $del=DB::table('token_balance')->where('address',$payer)->delete();
                    //验证余额是否充足 二次验证
                    $dalance_data2=$this->getBalance($payer,$contract);
                    if($dalance_data2['code']==200){
                        if($amount>$dalance_data2['balance']){
                            $data['code']=402;
                            $data['message']='Token 余额不足';
                            return $data;
                        }else{
                            DB::beginTransaction(); //开启事务
                            $modelTokenBalance=new TokenBalance();
                            $up_balance=bcsub($dalance_data2['balance'],$amount,0);
                            $upinfo=$modelTokenBalance->updateBalance2($data['from'],$up_balance);
                            if($upinfo){
                                DB::commit();  //提交
                            }else{
                                DB::rollback();  //回滚
                            }

                        }
                    }

                }else{
                    DB::beginTransaction(); //开启事务
                    $modelTokenBalance=new TokenBalance();
                    $up_balance=bcsub($dalance_data['balance'],$amount,0);
                    $upinfo=$modelTokenBalance->updateBalance2($data['from'],$up_balance);
                    if($upinfo){
                        DB::commit();  //提交
                    }else{
                        DB::rollback();  //回滚
                    }

                }
            }
            $token = $erc20->token($contract);
            $data["data"] = $token->encodedTransferData($payee,$amount);
            $gasPrice2= bcdiv(bcmul($gasPrice3,'2',18), "1000000000000000000",18);
            if($gasPrice2<0.00000005){
                $gasPrice2= '0.00000005';
            }
            $transaction = $geth->personal()->transaction($payer, $contract)->gas(80000,$gasPrice2)->amount("0")->data($data["data"]); // Our encoded ERC20 token transfer data from previous step
            //$transaction->nonce=$from_data['nonce'];
            $nonce=$this->nonce($request->from);
            $transaction->nonce=$nonce;
            //dd($transaction,$data);
            $res = $transaction->send($data['password']); // Replace "secret" with actual passphrase of SENDER's ethereum
            if($res){
                $new_nonce=$nonce+1;
                //Session::put($payer,$new_nonce);
                Redis::set($payer,$new_nonce);
                $r_data['code']=200;
                $r_data['message']=$res;
                DB::table('token_w_from_data')->where('id',$id)->update(array('hash'=>$res,'nonce'=>$nonce));
                return $r_data;
            }
            return 0;
        } catch (\Exception $exception) {
            dd($exception);
            //恢复金额
            $amount=$request->input('amount');
            $decimals=$request->input('decimals');
            $ling='1';
            for ($i=0;$i<$decimals;$i++){
                $ling.='0';
            }
            //计算恢复金额
            $amount= bcmul($amount, $ling);
            $modelTokenBalance=new TokenBalance();
            $dalance_data=$this->getBalance($request->input('from'),$request->input('contract'));
            $up_balance=bcadd($dalance_data['balance'],$amount,0);
            $upinfo=$modelTokenBalance->updateBalance2($request->input('from'),$up_balance);
            //dd($exception);
            $data['code']=405;
            $data['message']='error';
            return $data;
        }

    }

    public function test(){
        $address="0xa25b48DE6ae47e59cBd9f1Ba0f853dC110E5C616";
        $contractaddress="0x168ed6f9ed33c6c4c4efbe35f339d90ed1f32b1d";
        $data=$this->getBalance($address,$contractaddress);
        dd($data);

    }

    //获取地址余额
    public function getBalance($address,$contractaddress){
        $modelTokenBalance=new TokenBalance();
        $datainfo=$modelTokenBalance->get($address,$contractaddress);
        if($datainfo){
            $data['code']=200;
            $data['balance']=$datainfo->balance;
            return $data;
        }
        $message = $this->curl_get_https('https://api.etherscan.io/api?module=account&action=tokenbalance&contractaddress='.$contractaddress.'&address='.$address.'&tag=latest&apikey=PYKXE2UX1G9P6HJYHCEA1UPGWUJYX9P1GT');
        $task_message = json_decode($message, true);
        if($task_message['status']==='1'){
            $tokenBalanceData['balance']=$task_message['result'];
            $tokenBalanceData['contract']=$contractaddress;
            $tokenBalanceData['address']=$address;
            $tokenBalanceData['update_time']=date('Y-m-d H:i:s');
            //dd($tokenBalanceData);
            $modelTokenBalance=new TokenBalance();
            $info=$modelTokenBalance->add($tokenBalanceData);
            if($info){
                $this->addressl($address);
                $data['code']=200;
                $data['balance']=$task_message['result'];
                return $data;
            }else{
                $data['code']=402;
                return $data;
            }
        }else{
            $data['code']=402;
            return $data;
        }
    }

    //地址发去监听
    public function addressl($address){
        $api_url = 'http://127.0.0.1:36/accountAddress?apikey=NIuse1XCyOvX$5Y&address='.$address.'&platformName=token';
        $this->curl_get_https($api_url);
        return 200;
    }

    //token余额更新
    public function updateBalance(Request $request){
//        $hash=$request->erc20_tx_hash;
//        //$url2 = 'http://141.193.156.178:36/findERC20TransactionByHash?apikey=123456&erc20_tx_hash='.$hash;
//        $url2 = 'http://127.0.0.1:36/findERC20TransactionByHash?apikey=B@qWhJcUJQcBshdI&erc20_tx_hash='.$hash;
//        //dd(json_decode($this->curl_get_https($url2), true));
//        $task_message = json_decode($this->curl_get_https($url2), true)['txResults'][0];
//        if(is_array($task_message)){
//            $modelTokenBalance=new TokenBalance();
//            $datainfo=$modelTokenBalance->get($task_message['erc20_to'],$task_message['erc20_token']);
//            if($datainfo){
//                $balance=bcadd($datainfo->balance,$task_message['erc20_value']);
//                $info=$modelTokenBalance->updateBalance($datainfo->id,$balance);
//                return $info;
//            }
//        }
        $contractaddress=$request->erc20_token;
        $address=$request->erc20_to;
        $message = $this->curl_get_https('https://api.etherscan.io/api?module=account&action=tokenbalance&contractaddress='.$contractaddress.'&address='.$address.'&tag=latest&apikey=PYKXE2UX1G9P6HJYHCEA1UPGWUJYX9P1GT');
        $task_message = json_decode($message, true);
        if($task_message['status']==='1') {
            $tokenBalanceData['balance'] = $task_message['result'];
            $modelTokenBalance=new TokenBalance();
            $datainfo=$modelTokenBalance->get($address,$contractaddress);
            $info = $modelTokenBalance->updateBalance($datainfo->id, $tokenBalanceData['balance']);
            return $info;
        }
        return 0;
    }

    //请求
    public function curl_get_https($url)
    {
        $curl = curl_init(); // 启动一个CURL会话
        curl_setopt($curl, CURLOPT_URL, $url);
        curl_setopt($curl, CURLOPT_HEADER, 0);
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, false); // 跳过证书检查
        curl_setopt($curl, CURLOPT_SSL_VERIFYHOST, 2);  // 从证书中检查SSL加密算法是否存在
        curl_setopt($curl, CURLOPT_TIMEOUT, 30);
        $tmpInfo = curl_exec($curl);     //返回api的json对象
        curl_close($curl);//关闭URL请求
        return $tmpInfo;    //返回json对象
    }

    public function getBalance2(Request $request){
        $validator = Validator::make($request->all(), [
            'address' => 'required',
            'key' => 'required',

        ]);

        $errors = json_decode(json_encode($validator->errors()), true);
        //判断参数不为空
        if ($validator->fails()) {
            $data['code']=402;
            $data['message']=$errors;
            return $data;
        }
        if(!empty($request->contract_address)){
            //判断key
            $key=$request->input('key');
            $hash = md5('i@z%cBVz5^fDN9Q0'.'xinggong'.$request->contract_address.$request->address);
            if($key!=$hash){
                $data['code']=402;
                $data['message']='Key error';
                return $data;
            }
            $message = $this->curl_get_https('https://api.etherscan.io/api?module=account&action=tokenbalance&contractaddress='.$request->contract_address.'&address='.$request->address.'&tag=latest&apikey=3FVDDCH2IJRZYUDDSA69WA8EAUAGC8HZXQ');
            $task_message = json_decode($message, true);
            if($task_message['status']==='1'){
                $data['code']=200;
                $balance=bcdiv($task_message['result'],"1000000",6);
                $data['balance']=$balance;
                return $data;
            }else{
                $data['code']=402;
                return $data;
            }
        }else{
            //判断key
            $key=$request->input('key');
            $hash = md5('i@z%cBVz5^fDN9Q0'.'xinggong'.$request->address);
            if($key!=$hash){
                $data['code']=402;
                $data['message']='Key error';
                return $data;
            }
            $gethrpc=new Eth('http://127.0.0.1:2406');//本机
            $balance=$gethrpc->eth_getBalance($request->address,'latest');
            $balance=bcdiv(hexdec($balance["result"]),"1000000000000000000",18);
            $data['code']=200;
            $data['balance']=$balance;
            return $data;
        }

    }

    private function blockNumber(){

        try {
            $infura=new Eth('https://mainnet.infura.io/v3/ca6382c272c94b5ab65937ce7213e94f');//infura网络
            $infura_data=$infura->eth_blockNumber();
            $gethrpc=new Eth(config('app.eth'));//geth网络
            $gethrpc_data=$gethrpc->eth_blockNumber();
            $small=hexdec($infura_data['result'])-5;
            $big=hexdec($infura_data['result'])+5;
            if($small<=hexdec($gethrpc_data['result']) and $big>=hexdec($gethrpc_data['result']) ){
                return 1;
            }
            //$result=$gethrpc->personal_newAccount('company');
            //$result = $gethrpc->personal_unlockAccount('0x40bd8d76074d2ed3a185f10f7cefb96a4e6c024b','test');//解锁
            return 0;
        } catch (\Exception $exception) {
            return 0;
        }

    }

    public function address(Request $request){
        //判断key
        $key=$request->input('key');
        $hash = md5('i@z%cBVz5^fDN9Q0'.'xinggong');
        if($key!=$hash){
            $data['code']=402;
            $data['message']='Key error';
            return $data;
        }
        $gethrpc=new Eth(config('app.eth'));//测试网络
        $result=$gethrpc->personal_newAccount('vd!LiedNJ9DkGRpA');
        $data["code"]="200";
        $data["address"]=$result['result'];
        $info=DB::table("accounts")->insert(array("address"=>$result['result'],"platformName"=>"xg"));
        //$result = $gethrpc->personal_unlockAccount(config("app.getFeeAddress"),config("app.getFeeAddressPassword"));//解锁
        return $data;
    }
    public function  nonce($from){
        $a=Redis::get($from);
        if($a!=''){
            //$nonce=Session::get($from);
            $nonce=Redis::get($from);
            return $nonce;
        }else{
            $gethrpc=new Eth(config('app.eth'));
            $bal=$gethrpc->eth_getTransactionCount($from,'latest');
            $nonce=hexdec($bal['result']);
            Redis::set($from,$nonce);
            //Session::put($from,$nonce);
            return $nonce;
        }

    }

    public function getnonce(Request $request){


        $from=$request->from;

//        $nonce= $this->nonce($from);
//        $new_nonce=$nonce+1;
//        //Session::put($payer,$new_nonce);
//        Redis::set($from,$new_nonce);
        return $this->nonce($from);

        $a=Session::has($from);
        if($a){
            $nonce=Session::get($from);
            return $nonce;
        }else{
            $nonce=Session::get($from);
            return $nonce.'111';
        }

        $value=$request->value;
        if($from!=''){
            Session::put($from,$value);
            return 'ok';
        }else{
            $nonce=Session::get('aaa');
            return $nonce;
        }

    }

}
