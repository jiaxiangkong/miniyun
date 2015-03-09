<?php
/**
 * Miniyun 文件上传下载公共方法
 * @author app <app@miniyun.cn>
 * @link http://www.miniyun.cn
 * @copyright 2014 Chengdu MiniYun Technology Co. Ltd.
 * @license http://www.miniyun.cn/license.html 
 * @since 1.6
 */
class MFilesCommon extends MModel {
    private  $currentUserId;
    private $share_filter=null;
    /**
     * 初始化需要的参数
     */
    public static function initMFilesCommon() {
        // 参数默认值
        $overwrite = true; // 是否覆盖,true-覆盖,false-不覆盖，默认值true
        $locale = "bytes"; // 返回metadata时，size单位转换,默认值为 bytes
        // 文件的版本。如果$parent_rev为文件最新版本，则覆盖，否则生成冲突文件。
        // e.g "test.txt"重命名为"test (conflicted copy).txt"，
        // 如果$parent_rev对应的版本不存在，则不会保存,返回400错误
        $parentRev = 0;
        if (isset ( $_REQUEST ["locale"] )) {
            $locale = $_REQUEST ["locale"];
        }
        if (isset ( $_REQUEST ["overwrite"] )) {
            $overwrite = $_REQUEST ["overwrite"];
            if (is_string ( $overwrite ) === true) {
                if (strtolower ( $overwrite ) === "false") {
                    $overwrite = false;
                } elseif (strtolower ( $overwrite ) === "true") {
                    $overwrite = true;
                }
            }
        }
        if (isset ( $_REQUEST ["parent_rev"] )) {
            $parentRev = $_REQUEST ["parent_rev"];
        }
        
        // 新加参数文件属性"创建时间"和"修改时间"
        $createTime = isset($_REQUEST["create_time"]) ? (int)$_REQUEST ["create_time"] : time();
        $updateTime = isset($_REQUEST["update_time"]) ? (int)$_REQUEST ["update_time"] : time();
        
        // 如果时间小于2000-01-01 00:00:00 (946681200),则使用当前时间
        if ($createTime < 946681200) {
            $createTime = time();
        }
        if ($updateTime < 946681200) {
            $createTime = time();
        }
        
        $user                          = MUserManager::getInstance ()->getCurrentUser ();
        $device                        = MUserManager::getInstance ()->getCurrentDevice ();
        $fileCommon                   = new MFilesCommon ();
        $fileCommon->overwrite        = $overwrite;
        $fileCommon->locale           = $locale;
        $fileCommon->parent_rev       = $parentRev;
        $fileCommon->user_id          = $user["user_id"];
        $fileCommon->user_nick        = $user["user_name"];
        $fileCommon->user_device_id   = $device["device_id"];
        $fileCommon->user_device_name = $device["user_device_name"];
        $fileCommon->action           = MConst::CREATE_FILE;
        $fileCommon->file_update_time = $updateTime;
        $fileCommon->file_create_time = $createTime;
        $fileCommon->conflict         = false; // 生成冲突文件标志
        $fileCommon->space            = $user["space"];
        $fileCommon->used_space       = $user["usedSpace"];
        $fileCommon->create_event     = true;
        return $fileCommon;
    }

    /**
     * 保存文件版本
     * @param $tmpName
     * @param $signature
     * @param $size
     * @param bool $move
     * @throws MFilesException
     */
    public function saveFile($tmpName, $signature, $size, $move = true) {
        //data源处理对象
        $dataObj = Yii::app()->data;
        //
        // 文件内容保存路径
        //
        $storePath = MiniUtil::getPathBySplitStr ( $signature );
        if ($dataObj->exists( dirname ( $storePath ) ) === false) {
            MUtils::MkDirs ( dirname ( $storePath ) );
        }
        $version = MiniVersion::getInstance()->getBySignature( $signature );
        if ($version != null) {
            //
            // 文件版本id
            //
            $this->version_id = $version["id"];
            $this->file_hash  = $version["file_signature"];
            if ($dataObj->exists( $storePath ) == false) {
                if ($dataObj->put($tmpName, $storePath, true) == false) {
                    throw new MFilesException ( Yii::t('api', MConst::INTERNAL_SERVER_ERROR ), MConst::HTTP_CODE_500 );
                }
            }
            if ($move === true) {
                unlink($tmpName);
            }
            return;
        }
        // 移动临时文件到保存路径中
        if ($move === true) {
            if ($dataObj->put( $tmpName, $storePath, true) == false) {
                throw new MFilesException ( Yii::t('api', MConst::INTERNAL_SERVER_ERROR ), MConst::HTTP_CODE_500 );
            }
        }
        //
        // 创建version
        //
        $version = MiniVersion::getInstance()->create($signature, $size, $this->type);
        if ($version == null) {
            throw new MFilesException ( Yii::t('api', MConst::INTERNAL_SERVER_ERROR ), MConst::HTTP_CODE_500 );
        }
        //
        // 文件版本id
        //
        $this->version_id = $version["id"];
        $this->file_hash  = $version["file_signature"];
    }
    /**
     * 创建文件详情
     * @since 1.0.7
     */
    public function saveFileMeta() {
        $this->share_filter = MSharesFilter::init();
        $parentPath = $this->parent_path;
        $currentUserId = $this->user_id;
        $this->currentUserId = $currentUserId;

        // 检查文件名是否有效
        if (MUtils::checkNameInvalid($this->file_name)){
            throw new MFileopsException(Yii::t('api','bad request'."6"), MConst::HTTP_CODE_400);
        }
        // 获取父目录信息
        $parentCheckHandler                  = new MCreateFolderController();
        $parentCheckHandler->_user_id        = $this->user_id;
        $parentCheckHandler->_user_device_id = $this->user_device_id;
//        $parent_check_handler->share_filter    = $this->share_filter;
        if(empty($parentPath) || $parentPath=="/"||$parentPath=="/".$currentUserId){
            $this->parent_file_id = 0;
        }else{
            $this->parent_file_id                  = $parentCheckHandler->handlerParentFolder($this->parent_path);
        }
        $isSharedPath = false;//主要用于判断是否为被共享文件
        if(empty($parentPath) || $parentPath=="/"){//说明此时在根目录下创建文件，有创建权限
            $canCreateFile = true;
            $this->path = "/".$currentUserId.$this->path;
            $parentPath = "/";
        }else{//非根目录情况
            $canCreateFile = false;
            $arr = explode('/',$parentPath);
            $masterId= $arr[1];
            if($masterId == $currentUserId){//自己目录下皆有创建权限
                $model = new GeneralFolderPermissionBiz($parentPath);
                if($model->isParentShared($parentPath)){//如果是父目录被共享
                    $isSharedPath = true;
                }
            }else{//别人共享目录下判断有无创建权限

                $this->user_id = $masterId;
                $isSharedPath = true;

            }
            if($isSharedPath){
                $permissionArr = UserPermissionBiz::getInstance()->getPermission($parentPath,$currentUserId);
                if(!empty($permissionArr)){
                    $privilegeModel = new PrivilegeBiz();
                    $this->share_filter->slaves =$privilegeModel->getSlaveIdsByPath($permissionArr['share_root_path']);
                    $this->share_filter->is_shared = true;
                    $permission = $permissionArr['permission'];
                    $create_file_num = substr($permission,4,1);
                    if($create_file_num==1){
                        $canCreateFile = true;
                    }
                }else{
                    $canCreateFile = true;
                }
            }else{
                $canCreateFile = true;
            }
        }
        // 保存到数据库中的地址
        $this->file_path                       = $this->path;

        // 从数据库中获取路径对应的文件，未删除的
        //
        $fileDetail = MFiles::queryFilesByPath ( $this->file_path );
        $this->create_file = false;
        if ($fileDetail == false || count ( $fileDetail ) == 0) { // 创建文件
            $this->create_file = true;
            $fileDetail = new MFiles ();
        } else { // 文件存在判断为修改文件(如果按照正常逻辑)
            $fileDetail = MFiles::exchange2Object ( $fileDetail );
            //
            // 判断指向的是未删除的非文件，否则返回错误
            //
            if ($fileDetail->file_type != MConst::OBJECT_TYPE_FILE) {
                throw new MFilesException ( Yii::t('api', "There is already a folder at the given destination" ), MConst::HTTP_CODE_403 );
            }
        }
        $this->modifyFile ( $fileDetail );
        if (isset($fileDetail->is_deleted)) {
            $this->spaceFilter ($this->size - $fileDetail->file_size);   // 过滤器，空间大小计算
        }
        if (isset($fileDetail->event_uuid)) {
            $this->event_uuid = $fileDetail->event_uuid;
        }

        if($canCreateFile==false){
            throw new MFilesException ( Yii::t('api', "No Permission" ), MConst::HTTP_CODE_409 );
        }
        $this->conflictFile ();
        $this->renameFile ();
        $this->createFile ( $fileDetail );

        
        $this->success = true;
        //
        // 创建成功为每个用户
        //
        if ($this->create_event) {
            $path = MUtils::convertStandardPath($this->parent_path . '/' . $this->file_name);
            $this->context = array( "hash"  => $this->file_hash,
                              "rev"         => (int)$this->version_id,
                              "bytes"       => (int)$this->size,
                              "update_time" => (int)$this->file_update_time,
                              "create_time" => (int)$this->file_create_time 
                              );
//            $this->share_filter->handlerAction($this->action, $this->user_device_id, $path, $this->context);
        }

        // 异步文档转换
        do_action('file_upload_after', array(
            "signature"=>$this->file_hash,
            "file_name"=>$this->file_name,
        ));
    }
    
    /**
     * 组装返回值，并输出
     */
    public function buildResult() {
        if (! isset ( $this->success ) || $this->success != true) {
            throw new MFilesException ( Yii::t('api', MConst::INTERNAL_SERVER_ERROR ), MConst::HTTP_CODE_500 );
        }
        $pathInfo                 = MUtils::pathinfo_utf($this->share_filter->src_path);
        $response                  = array ();
        $response ["size"]         = MUtils::getSizeByLocale ( $this->locale, $this->size );
        $response ["rev"]          = $this->version_id;
        $response ["thumb_exists"] = $this->isExistThumbnail ();
        $response ["bytes"]        = intval($this->size);
        $response ["modified"]     = MUtils::formatIntTime($this->file_update_time);
        $response ["path"]         = MUtils::convertStandardPath ( $pathInfo['dirname'] . "/" . $this->file_name );
        $response ["is_dir"]       = false;
        $response ["icon"]         = "file";
        $response ["root"]         = $this->root;
        $response ["mime_type"]    = $this->type;
        $response ["revision"]     = intval($this->version_id); // 版本
        $response ["hash"]         = $this->file_hash; // 版本
        //当且仅当文件在upload_block下，version没有记录，将在这里处理相关的逻辑
        $response ["success"]      = true; 
		//
		// dataserver 增加需要的返回值
		// by Kindac 
		// since 2013/06/25
		//
		$response ["temp_file_path"]  = $this->file_path;
        //
        // 20120720 增加返回event_uuid用于客户端判断不同类型的事件编码 nava
        //
        if (isset($this->event_uuid)) {
            $response["event_uuid"]  = $this->event_uuid; // 事件编码
        }
        echo json_encode ( $response );
        exit();
    }
    
    /**
     * web返回值处理
     */
    public function buildWebResponse()
    {
        if (! isset ( $this->success ) || $this->success != true) {
            throw new MFilesException ( Yii::t('api', MConst::INTERNAL_SERVER_ERROR ), MConst::HTTP_CODE_500 );
        }
        // 查询数据库，找出对应id 
        $fileDetail = UserFile::model()->find(
                                            array(
                                            'condition'=>'file_path=:file_path',
                                            'params'=>array(
                                            ':file_path'=>"/" . $this->user_id . $this->path
                                            )
                                            ));
        if (empty($fileDetail))
        {
            throw new MFileopsException(
                                        Yii::t('api','Can not find the folder.'),
                                        MConst::HTTP_CODE_404);
        }
        $pathInfo                = MUtils::pathinfo_utf($this->share_filter->src_path);
        $user                    = MiniUser2::getInstance()->getUser2($this->user_id);
        $total                   = $user["space"];
        $usedSpace               = $user["usedSpace"]; 
        $uploadSizeRemain      = $total - $usedSpace;
        $cid                     = isset($_REQUEST['cid']) ? $_REQUEST['cid'] : 0;
        $data                    = Array();
        $data["rev"]             = $this->version_id;
        $data["is_user"]         = 1;
        $data["user_id"]         = $this->user_id;
        $data["group_id"]        = 0;
        $data["aid"]             = 0;
        $data["user_ip"]         = "0.0.0.0";
        $data["upload_type"]     = 1;
		//
		// dataserver 修改需要的返回值 文件path
		// by Kindac 
		// since 2013/06/25
		//
        $data["temp_file_path"]     = $this->file_path;
        $data["temp_file_name"]     = $this->file_name;
        $data["temp_file_sha1"]     = $this->file_hash;
        $data["is_share"]           = 0;
        $data["file_ext"]           = $pathInfo["extension"];
        $data["file_name_sort"]     = strlen($this->file_name);
        $data["file_size"]          = $this->size;
        $data["file_description"]   = "";
        $data["pick_time"]          = "";
        $data["is_collect"]         = 0;
        $data["file_status"]        = 1;
        $data["file_ptime"]         = time();
        $data["file_id"]            = $fileDetail->id;
        $data["ico"]                = $pathInfo["extension"];
        $data["area_id"]            = $cid;
        $data["category_id"]        = $cid;
        $data["upload_size_remain"] = $uploadSizeRemain;
        $ret                        = Array();
        $ret["state"]               = true;
        $ret["data"]                = $data;
        echo json_encode ( $ret );
    }
    
    /**
     * 文件标记为删除的处理逻辑，将文件is_deleted 修改为0
     * @param array $fileDetail
     * @throws
     */
    private function creatFileDeleted($fileDetail) {
        //
        // 如果创建文件不为true 不执行
        //
        if ($this->create_file == false) {
            return;
        }
        //
        // 对象非文件，返回
        // TODO 按照dropbox的方式，不同类型的，会将其覆盖，调用删除文件逻辑
        //
        if ($fileDetail->file_type != MConst::OBJECT_TYPE_FILE) {
            throw new MFilesException ( Yii::t('api', MConst::INTERNAL_SERVER_ERROR ), MConst::HTTP_CODE_500 );
        }
        
        $metaValue = null;
        $fileMeta = MFileMetas::queryFileMeta ( $fileDetail->file_path, MConst::VERSION );
        if ($fileMeta) {
            $metaValue = $fileMeta[0]['meta_value'];
        }

        
        //
        // 空间判断
        //
        $sizeDiff = $this->size - $fileDetail->file_size;
        $this->spaceFilter($sizeDiff);
        
        $this->action = MConst::CREATE_FILE;
        //
        // 文件meta属性，版本信息
        //
        $version = MUtils::getFileVersions ($this->user_device_name, 
                                            $this->size, 
                                            $this->version_id, 
                                            $this->action, 
                                            $this->user_id, 
                                            $this->user_nick, 
                                            $metaValue
                                            );
        //
        // 需要更新的数据字段和值
        //
        $updates = array ();
        $updates ["version_id"] = ( int ) $this->version_id;
        $updates ["file_size"] = $this->size;
        $updates ["file_update_time"] = $this->file_update_time;
        $updates ["is_deleted"] = 0;
        $updates ["event_uuid"] = MiniUtil::getEventRandomString ( MConst::LEN_EVENT_UUID );
        
        
        //
        // 执行更新操作
        //
        if (MFiles::updateFileDetailById ( $fileDetail->id, $updates ) === false) {
            throw new MFilesException ( Yii::t('api', MConst::INTERNAL_SERVER_ERROR ), MConst::HTTP_CODE_500 );
        }
        //
        // 保存事件
        //
        $this->context = array( "hash"  => $this->file_hash,
                          "rev"         => (int)$this->version_id,
                          "bytes"       => (int)$this->size,
                          "update_time" => (int)$this->file_update_time,
                          "create_time" => (int)$this->file_create_time );
        
        $retVal = MiniEvent::getInstance()->createEvent (  $this->currentUserId,
                                           $this->user_device_id, 
                                           $this->action, 
                                           $this->file_path,
                                           serialize($this->context),
                                           $updates ["event_uuid"],
                                           $this->share_filter->type
                                           );
        if ($retVal === false) {
            throw new MFilesException ( Yii::t('api', MConst::INTERNAL_SERVER_ERROR ), MConst::HTTP_CODE_500 );
        }

        $this->share_filter->handlerAction($this->action, $this->user_device_id, $this->file_path, $this->context);
        if ($metaValue){
            if (MUtils::isExistReversion ( $this->version_id, $metaValue ) == false) {
                // 文件版本引用次数更新 
                if (MiniVersion::getInstance()->updateRefCount( $this->version_id ) == false) {
                    throw new MFilesException ( Yii::t('api', MConst::INTERNAL_SERVER_ERROR ), MConst::HTTP_CODE_500 );
                }
            }
            $retVal = MFileMetas::updateFileMeta ( $this->file_path, MConst::VERSION, $version );
        } else {
            $retVal = MFileMetas::createFileMeta ( $this->file_path, MConst::VERSION, $version );
        }
        // 保存版本历史 
        if ($retVal == false) {
            throw new MFilesException ( Yii::t('api', MConst::INTERNAL_SERVER_ERROR ), MConst::HTTP_CODE_500 );
        }
    }
    /**
     * 文件修改逻辑处理，包括冲突处理
     * @param array $fileDetail
     * @throws
     * @return array
     */
    private function modifyFile($fileDetail) {
        // 判断是否重写
        if ($this->overwrite == false) {
            $this->create_file = true;
            return;
        }
        //
        // 如果不为修改文件，则返回
        //
        if ($this->create_file == true) {
            return;
        }

        $fileMeta = MFileMetas::queryFileMeta ( $fileDetail->file_path, MConst::VERSION );
        if ($fileMeta == false || empty ( $fileMeta )) {
            $this->create_file = true;
            MFiles::updateFileDetailById($fileDetail->id, array('is_deleted'=>1));
            return;
        }
        //
        // 检查parent_rev是否存在
        //
        if (MUtils::isExistReversion ( $this->parent_rev, $fileMeta [0] ["meta_value"] ) == false) {
            $this->parent_rev = 0;
        }
        
        //
        // 修改的内容一致
        //
        if ($this->version_id == $fileDetail->version_id) {
            $this->create_event = false;
            return;
        }
        //
        // 生成冲突文件
        //
        if ($fileDetail->version_id != $this->parent_rev && $this->parent_rev != 0) {
            $this->conflict = true;
            $this->create_file = true;
            return;
        }
        
        //
        // 空间判断
        //
        $sizeDiff = $this->size - $fileDetail->file_size;
        $this->spaceFilter($sizeDiff);
        // 
        // 修改文件
        //
        $this->action = MConst::MODIFY_FILE;

        //如果在共享目录内进行修改则进行修改权限判断
        if ($this->share_filter->is_shared){
            $this->share_filter->hasPermissionExecute($fileDetail->file_path, MPrivilege::FILE_MODIFY);
        }

        //
        // 文件meta属性，版本信息
        //
        $version = MUtils::getFileVersions ($this->user_device_name,
                                            $this->size, 
                                            $this->version_id, 
                                            $this->action, 
                                            $this->user_id, 
                                            $this->user_nick, 
                                            $fileMeta [0] ["meta_value"]
                                            );
        
        //
        // 需要更新的数据字段和值
        //
        $updates = array ();
        $updates ["version_id"] = ( int ) $this->version_id;
        $updates ["file_size"]  = $this->size;
        $updates ["file_update_time"] = $this->file_update_time;
        $updates ["event_uuid"] = MiniUtil::getEventRandomString ( MConst::LEN_EVENT_UUID );
        //
        // 执行更新操作
        //
        if (MFiles::updateFileDetailById ( $fileDetail->id, $updates ) === false) {
            throw new MFilesException ( Yii::t('api', MConst::INTERNAL_SERVER_ERROR ), MConst::HTTP_CODE_500 );
        }
        //
        // 保存事件
        //
        $this->context = array( 
                  "hash"        => $this->file_hash,
                  "rev"         => (int)$this->version_id,
                  "bytes"       => (int)$this->size,
                  "update_time" => (int)$this->file_update_time,
                  "create_time" => (int)$this->file_create_time);
        $retVal = MiniEvent::getInstance()->createEvent ( $this->currentUserId,
                                          $this->user_device_id, 
                                          $this->action,
                                          $fileDetail->file_path,
                                          serialize($this->context), 
                                          $updates ["event_uuid"],
                                          $this->share_filter->type
                                       );
        if ($retVal === false) {
            throw new MFilesException ( Yii::t('api', MConst::INTERNAL_SERVER_ERROR ), MConst::HTTP_CODE_500 );
        }
        $this->share_filter->handlerAction($this->action, $this->user_device_id, $fileDetail->file_path, $this->context);
        //
        // 只有版本历史中不存在的时候才更新
        //
        if (MUtils::isExistReversion ( $this->version_id, $fileMeta [0] ["meta_value"] ) == false) {
            // 文件版本引用次数更新 
            if (MiniVersion::getInstance()->updateRefCount( $this->version_id ) == false) {
                throw new MFilesException ( Yii::t('api', MConst::INTERNAL_SERVER_ERROR ), MConst::HTTP_CODE_500 );
            }
        }
        
        // 保存版本历史 
        if (MFileMetas::updateFileMeta ( $fileDetail->file_path, MConst::VERSION, $version ) == false) {
            throw new MFilesException ( Yii::t('api', MConst::INTERNAL_SERVER_ERROR ), MConst::HTTP_CODE_500 );
        }
    }
    /**
     * 冲突文件处理
     */
    private function conflictFile() {
        if ($this->conflict == false) {
            return;
        }
        //
        // 生成冲突文件
        //
        $paths = MUtils::pathinfo_utf ( $this->file_name );
        $baseName = $paths ["filename"];
        $extension = $paths ["extension"];
        if (is_null ( $extension ) || $extension == "") {
            $this->file_name = "$baseName" . MConst::CONFLICT_FILE_NAME;
        } else {
            $this->file_name = "$baseName" . MConst::CONFLICT_FILE_NAME . ".$extension";
        }
        //
        // 查询未标记为删除的文件
        //
        $children = MFiles::queryChildrenByParentId ($this->user_id,$this->parent_file_id );
        if ($children === false) {
            throw new MFilesException ( Yii::t('api', MConst::INTERNAL_SERVER_ERROR ), MConst::HTTP_CODE_500 );
        }
        $names = array ();
        foreach ( $children as $k => $v ) {
            $names [strtolower($v ["file_name"])] = $v ["file_name"];
        }
        //
        // 执行重命名文件操作
        //
        $this->file_name   = MUtils::getConflictName ( $this->file_name, $names );
        $this->path        = MUtils::convertStandardPath($this->parent_path . "/" . $this->file_name);
        $this->file_path   = "/" . $this->user_id . $this->path;
        $this->create_file = true;
    }
    /**
     * 创建文件
     * @param array $fileDetail
     * @throws
     * @return array
     */
    private function createFile($fileDetail) {
        //
        // 如果创建文件标志为false，则不执行创建
        //
        if ($this->create_file == false) {
            return;
        }
        
        //
        // 是否有标记为删除的对象,可能存在多个
        //
        $conflictFile = MFiles::queryFilesByPath ( $this->file_path, TRUE );
        if ($conflictFile != false && empty ( $conflictFile ) == false) {
            foreach ( $conflictFile as $file ) {
                //
                // 如果非文件类型，删除
                //
                if ($file ["file_type"] == MConst::OBJECT_TYPE_FILE) {
                    $fileDetail = MFiles::exchange2Object ( $file, TRUE);
                    $this->creatFileDeleted ( $fileDetail );
                    return;
                } else { // 彻底删除之后再进行创建
                    $trash = new Trash();
                    $trash->_userId = $this->user_id;
                    $trash->fromIds = $file['id'];
                    try {
                        $trash->invoke(Trash::DELETE);
                    } catch (Exception $e) {
                        throw new MFileopsException(
                                                Yii::t('api','Internal Server Error'),
                                                MConst::HTTP_CODE_500);
                    }
                }
            }
        }
        $this->spaceFilter ($this->size);   // 过滤器，空间大小计算
        $fileDetail->file_create_time = $this->file_create_time;
        $fileDetail->file_update_time = $this->file_update_time;
        $fileDetail->file_size = $this->size;
        $fileDetail->file_type = MConst::OBJECT_TYPE_FILE;
        $fileDetail->parent_file_id = $this->parent_file_id;
        $fileDetail->version_id = $this->version_id;
        $fileDetail->file_path  = $this->path;
        $fileDetail->file_name  = $this->file_name;
        $fileDetail->event_uuid = MiniUtil::getEventRandomString ( MConst::LEN_EVENT_UUID );
        $fileDetail->mime_type  = $this->type;

        //
        // 创建文件时，如果存在老的版本 需要兼容 不能覆盖
        //
        $metaValue = null;
        $fileMeta = MFileMetas::queryFileMeta ( $fileDetail->file_path, MConst::VERSION );
        if ($fileMeta){
            $metaValue = $fileMeta[0]['meta_value'];
        }
        //
        // 文件meta属性，版本信息
        //
        $version = MUtils::getFileVersions ( 
                                            $this->user_device_name,
                                            $fileDetail->file_size,
                                            $this->version_id, 
                                            $this->action, 
                                            $this->user_id, 
                                            $this->user_nick, 
                                            $metaValue );
        //
        // 保存文件元数据
        //
        $retVal = MFiles::CreateFileDetail ( $fileDetail, $this->user_id, $this->user_nick );
        if ($retVal === false) {
            throw new MFilesException ( Yii::t('api', MConst::INTERNAL_SERVER_ERROR ), MConst::HTTP_CODE_500 );
        }
        //
        // 保存事件
        //
        $this->context = array(
                  "hash"        => $this->file_hash,
                  "rev"         => (int)$this->version_id,
                  "bytes"       => (int)$this->size,
                  "update_time" => (int)$this->file_update_time,
                  "create_time" => (int)$this->file_create_time);
        $retVal = MiniEvent::getInstance()->createEvent ( $this->currentUserId,
                                          $this->user_device_id,
                                          $this->action,
                                          $this->file_path,
                                          serialize($this->context),
                                          $fileDetail->event_uuid,
                                          $this->share_filter->type
                                          );
        // 为每个共享用户创建事件
        $this->share_filter->handlerAction($this->action, $this->user_device_id, $this->file_path, $this->context);
        if (isset($fileDetail->event_uuid)) {
            $this->event_uuid = $fileDetail->event_uuid;
        }
        if ($retVal === false) {
            throw new MFilesException ( Yii::t('api', MConst::INTERNAL_SERVER_ERROR ), MConst::HTTP_CODE_500 );
        }
        // 文件版本引用次数更新 
        if (MiniVersion::getInstance()->updateRefCount( $this->version_id ) == false) {
            throw new MFilesException ( Yii::t('api', MConst::INTERNAL_SERVER_ERROR ), MConst::HTTP_CODE_500 );
        }
        if ($fileMeta){
            $retVal = MFileMetas::updateFileMeta ( $this->file_path, MConst::VERSION, $version );
        } else {
            $retVal = MFileMetas::createFileMeta ( $this->file_path, MConst::VERSION, $version );
            $pathArr = explode('/',$this->file_path);
            $user     = Yii::app()->session["user"];
            if((int)$pathArr[1]!==(int)$user['user_id']){//只有当被共享者在共享目录下创建文件时，才会记录create_id
                MFileMetas::createFileMeta ( $this->file_path, 'create_id', $user['user_id'] );
            }
        }
        if ($retVal === false) {
            throw new MFilesException ( Yii::t('api', MConst::INTERNAL_SERVER_ERROR ), MConst::HTTP_CODE_500 );
        }
    }
    /**
     * 当参数overwrite=false时，执行文件重命名，再创建文件
     */
    private function renameFile() {
        // overwrite=true，不执行之后操作
        if ($this->overwrite == true) {
            return;
        }
        $children = MFiles::queryChildrenByParentId ($this->user_id, $this->parent_file_id );
        if ($children === false) {
            throw new MFilesException ( Yii::t('api', MConst::INTERNAL_SERVER_ERROR ), MConst::HTTP_CODE_500 );
        }
        $names = array ();
        foreach ( $children as $k => $v ) {
            $names [strtolower($v ["file_name"])] = $v ["file_name"];
        }
        //
        // 执行重命名文件操作
        //
        $this->file_name   = MUtils::getConflictName ( $this->file_name, $names );
        $this->path        = MUtils::convertStandardPath($this->parent_path . "/" . $this->file_name);
        $this->file_path   = "/" . $this->user_id . $this->path;
        $this->create_file = true;
    }
    
    /**
     * 
     * 空间检查
     */
    private function spaceFilter($size) {
        if ($this->create_file == false) {
            return ;
        }
        // 空间检查
        $this->used_space += $size;
        if ($this->used_space > $this->space) {
            throw new MFilesException(Yii::t('api',"User is over storage quota."), 
            MConst::HTTP_CODE_507);
        }
    }
     
    /**
     * 判断是否存在缩略图
     * @return bool 
     */
    private function isExistThumbnail() {
        if ($this->size > MConst::MAX_IMAGE_SIZE || $this->size <= 0) {
            return false;
        }
        foreach ( MThumbnailBase::$supportTypes as $value ) {
            if ($value == $this->type) {
                return true;
            }
        }
        return false;
    }
}