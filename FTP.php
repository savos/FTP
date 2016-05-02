<?php

/**
 * class for FTP 
 */
class FTP_mobile 
{
    
    var $ERROR;
    var $MESSAGE;
    var $message;
    var $username;
    var $password;
    var $domain;
    var $workdir;
    var $connection;
    var $login;


    /**
     * constructor: set initial values
     * and initialize connection
     */
    public function __construct( $domain, $username, $password, $folder_path = "" )
    {
        $this->get_error_codes();
        $this->domain   = $this->get_environment_domain( $domain );
        $this->username = $username;
        $this->password = $password;
        $this->workdir  = "/".$folder_path;
        $this->message  = array();

        $this->connection = ftp_connect( $this->domain );

        if( ! $this->connection ) 
        {
            die( $this->ERROR["0"] );
        }
        else if( ! $this->login = ftp_login( $this->connection, $this->username, $this->password ) ) 
        {
            die( $this->ERROR[ "1" ] );
        }
        else if( ! ftp_chdir( $this->connection, $this->workdir ) )
        {
            die( $this->ERROR["8"] );
        }
	    if( ! ftp_pasv($this->connection, true) )
        {
            if( ! ftp_pasv($this->connection, false) )
            {
                die( $this->ERROR["15"] );
            }
        } 
    }


    /**
     * Close connection to ftp server
     * @return [none] 
     */
    protected function __destruct()
    {
        $ret = ftp_close( $this->connection );

        if( !$ret )
        {
            die( $this->ERROR[ "12" ] );
        }

        $this->connection = null;
        $this->login = null;
    }


    /**
     * Delete file from ftp server
     * @param  [string] $filename [name of file to be deleted]
     * @return [json]           [status, message]
     */
    public function delete_file( $filename ) 
    {
        $status = 1;

        $ret = ftp_delete( $this->connection , $this->workdir."/".$filename );

        if( !$ret ) 
        {
            $status = 0;
            $this->add_message( $this->ERROR["2"] );
        }

        return $this->return_value( $status );
    }


    /**
     * Create file on ftp server (alias for file upload)
     * @param  [string] $filename [file name]
     * @return [json]           [status, message]
     */
    public function create_file( $filename ) 
    {
        $status = 1;

        $ret = $this->save_file( $filename, "" );

        if( !$ret ) $status = 0;

        return $this->return_value( $status );
    }


    /**
     * [upload file from local to remote server]
     * @param  [type] $localfile_path  [local file name]
     * @param  string $remotefile_path [remote file name]
     * @return [json]           [status, message]
     */
    public function upload_file()
    {
        $status = 1;

        $filename = $_FILES["uploadFile"]["name"];
       
        $file_list = array(); 
        $file_list = ftp_nlist( $this->connection, "" );

        // Check if file already exists; If so, rename it according 'duplicate' file rule
        $remote_filename = $filename;

        while( in_array( $remote_filename, $file_list ) )
        {
            $remote_filename = $this->get_new_file_name( $remote_filename );
        }

        // Check file size
        if ( $_FILES["uploadFile"]["size"] > 250*1024*1024 ) 
        {
            $this->add_message( $this->ERROR["10"] );
        }

        // Prohibit certain file formats
        $extension = pathinfo( $filename, PATHINFO_EXTENSION );
        if( $extension == "exe" && $extension == "com" && $extension == "bat" ) 
        {
            $this->add_message( $this->ERROR["11"] );
        }
        else
        {
            $ret = ftp_put( $this->connection, $remote_filename, $_FILES["uploadFile"]["tmp_name"], FTP_BINARY );
        }

        if( !$ret ) $status = 0;

        unlink( $_FILES["uploadFile"]["tmp_name"] );

        return $this->return_value( $status );
    }


    /**
     * Get list of files in current directory
     * @return [json]           [status, message, folderlist]
     */
    public function get_file_list( $dirname = "" ) 
    {
        $status = 1;
        $ret       = array();
        $dirs      = array();
        $files     = array();
        $file_list = array();
        $file_list = ftp_nlist( $this->connection, "" );

        if( $file_list === false ) 
        {
            if( ! ftp_chdir( $this->connection, "/".$this->workdir ))
            {
                $status = 0;
            }
        }
        else
        {
            for( $i=0; $i < sizeof( $file_list ) ; $i++ )
            {
                $file_obj = strval( $file_list[ $i ] );

                if ( ftp_chdir( $this->connection, $file_obj ) ) 
                {
                    $dirs[ $file_obj ] = "dir";
                    ftp_chdir( $this->connection, "/".$this->workdir );
                } 
                else 
                {
                    $files[ $file_obj ] = "file";
                }
            }

            uksort( $dirs,  "strcasecmp" );
            uksort( $files, "strcasecmp" );

            $ret = $dirs + $files;
        }

        return $this->return_value( $status, json_encode( $ret ) );
    }


    /**
     * [Duplicate file in same directory]
     * @param  [string] $filename [filename]
     * @return [json]           [status, message]
     */
    public function duplicate_file( $filename )
    {
        $status = 1;
        $path = ( $this->workdir != "" ) ? $this->workdir."/" : "";
        $temp = new SplFileInfo( $this->workdir.$filename );

        // generate filename of duplicate
        $list = json_decode( $this->get_file_list(), true );
        $list = json_decode( $list[ "data" ], true );

        $new_filename = $this->get_new_file_name( $filename );

        while( isset( $new_filename, $list[ $new_filename ] ) )
        {
            $new_filename = $this->get_new_file_name( $new_filename );
        }
        // --- end

        $path = "ftp://".$this->username.":".$this->password."@".$this->domain;
        $path .= ( $this->workdir !== "" ) ? "/".$this->workdir."/" : "/";

        $ret = json_decode( $this->read_file_contents_base64( $filename ), true );
        $status = $ret[ "status" ];

        if( $status )
        {
            $ret = file_put_contents( $path.$new_filename, base64_decode( $ret[ "data" ] ) );
            if( $ret === false ) $status = 0;
        }

        return $this->return_value( $status );
    }


    /**
     * read file into string
     * @param  [string] $filename [file name]
     * @return [json]           [status, message]
     */
    public function read_file( $filename ) 
    {
        $ret = json_decode( $this->read_file_contents_base64( $filename ), true );

        $status = $ret[ "status" ];
        $data = base64_decode( $ret[ "data" ] );

        return $this->return_value( $status, $data );
    }


    /**
     * [read_file_contents_base64 reads and encode file content for string]
     * @param  [string] $filename [file name]
     * @return [json]            [status, message, data]
     */
    protected function read_file_contents_base64( $filename ) 
    {
        $status = 1;
        $path = ( $this->workdir !== "" ) ? $this->workdir."/" : "";

        ob_start();

        $ret = ftp_get( $this->connection, 'php://output', $path.$filename, FTP_BINARY );
        $data = base64_encode( ob_get_contents() );

        ob_end_clean();

        if( !$ret ) $status = 0;

        return $this->return_value( $status, $data );
    }


    /**
     * Save file content on ftp server (alias for file upload)
     * @param  [string] $filename [file name]
     * @return [json]           [status, message]
     */
    public function save_file( $filename, $data ) 
    {
        $status = 1;

        $path = "ftp://".$this->username.":".$this->password."@".$this->domain;
        $path .= ( $this->workdir !== "" ) ? "/".$this->workdir."/" : "/";

        $data = stripslashes( $data );
        $ret = file_put_contents( $path.$filename, $data );

        if( $ret === false ) $status = 0;

        return $this->return_value( $status );
    }


    /**
     * Set permission of a file
     * @param [type] $filename    [description]
     * @return [json]           [status, message]
     */
    public function set_permissions( $filename, $permissions ) 
    {
        $status = 1;

        $ret = ftp_chmod( $this->connection, octdec( $permissions ), $filename );

        if ( $ret === false ) 
        {
            $status = 0;
            $this->add_message( $this->ERROR["3"] );
        }

        return $this->return_value( $status, decoct( $ret ) );
    }


    /**
     * create folder on ftp server
     * @param  [string] $dirname [folder name]
     * @return [json]           [status, message]
     */
    public function create_dir( $dirname ) 
    {
        $status = 1;
        $ret = ftp_mkdir( $this->connection, $dirname );

        if ( !$ret ) 
        {
            $status = 0;
            $this->add_message( $this->ERROR["5"] );
        }

        return $this->return_value( $status );
    }


    /**
     * delete folder on ftp server recursively
     * @param  [string] $dirname [folder name]
     * @return [json]           [status, message]
     */
    public function delete_dir( $dirname ) 
    {
        $status = 1;

        $ret = ftp_rmdir( $this->connection, ftp_pwd( $this->connection )."/".$dirname );

        if ( !$ret ) 
        {
            ftp_chdir( $this->connection, $dirname );

            $file_list = ftp_nlist( $this->connection, "" );
            $file_list = array_diff( $file_list, array( ".", ".." ) );

            $file_list = array_values( $file_list );

            if( empty( $file_list ) ) 
            {
                $status = 0;
                ftp_cdup( $this->connection );
                $this->delete_dir( $dirname );
            }
            else
            {
                for( $i=0; $i < sizeof( $file_list ) ; $i++ )
                {
                    $file_obj = strval( $file_list[ $i ] );

                    if ( ftp_chdir( $this->connection, ftp_pwd( $this->connection )."/".$file_obj ) ) 
                    {
                        $this->delete_dir( $file_obj );
                    } 
                    else 
                    {
                        ftp_delete( $this->connection, ftp_pwd( $this->connection )."/".$file_obj );
                    }
                }
                $this->delete_dir( $dirname );
            }
        }

        return $this->return_value( $status );
    }    


    /**
    * internal function in duplicate_file method
    * that generates new filename when duplicated
    * @param  [type] $filename [description]
    * @return [string]           [new filename]
    */
    protected function get_new_file_name( $filename )
    {
        $temp = new SplFileInfo( $filename );

        $extension    = $temp->getExtension();
        $new_filename = $temp->getBasename( $extension );

        if( substr( $new_filename, -1, 1) == "." ) $new_filename = substr( $new_filename, 0, -1 );

        if( substr( $new_filename, -1 ) == ")" )
        {
            if( $open_pos = strrpos ( $new_filename , "(" ) )
            {
                $val = substr( $new_filename, $open_pos + 1, -1 );
                if( strval( intval( $val ) ) == $val )
                {
                    $val = intval( $val ) + 1;
                    $val = strval( $val );

                    $new_filename = substr( $new_filename, 0, $open_pos ) ."(" . $val .").".$extension;
                }
                else
                {
                    $new_filename .= "(1).".$extension;
                }
            }
        }
        else
        {
            $new_filename .= "(1).".$extension;
        }
        return $new_filename;
    }


    protected function return_value( $status, $data = "" )
    {
        $trace  = debug_backtrace();
        $method = $trace[1]['function'];

        $this->add_message( $this->MESSAGE[ $method ][ $status ] );

        $ret = array();
        $ret[ "status" ] = "".$status;
        $ret[ "message"] = $this->message;
        if( $data ) $ret [ "data" ] = $data;

        return json_encode( $ret );
    }


    /**
     * [Add message for return]
     * @param [string] $text [message to be added]
     */
    protected function add_message( $text )
    {
          $this->message = $text;
    }


    /**
     * [define error codes]
     * @return [array] [array with error codes]
    */
    protected function get_error_codes()
    {
        $this->ERROR = array (
             "0" => "FTP connection could not be established. Please check if domain is spelled correctly (without http, https, ftp etc.).",
             "1" => "Connection could not be established. Please check if username and password are spelled correctly.",
             "2" => "File couldn't be deleted. Please check if file name is spelled correctly and you have sufficient permisions to delete file.",
             "3" => "Can't change file permission. Please check if permission is in format '0nnn' and that you have sufficient privileges for such operation.",
             "4" => "Can't duplicate requested file. Please check if you have sufficient privileges for such operation.",
             "5" => "Can't create folder. Please check if you have sufficient privileges for such operation.",
             "6" => "Can't delete folder. Please check if you have sufficient privileges for such operation.",
             "7" => "Can't read content of file. Please check if file exists and that you have sufficient privileges for such operation.",
             "8" => "Can't change to requester folder. Please check if folder exists and that you have sufficient privileges for such operation.",
             "9" => "Can't read file/folder permissions. Please check if file/folder exists and that you have sufficient privileges for such operation.",
            "10" => "File exceeds max. allowed size of 250MB.",
            "11" => "Executable files are not allowed.",
            "12" => "FTP connection can't be closed.",
            "14" => "Can't delete folder because it is not empty.",
            "15" => "Can't determine ftp servers mode (active/pasive)."
        );

        $this->MESSAGE = array (
            "ftp_connect"    => array ( "FTP login failed.",                "FTP login succesfull." ),
            "ftp_close"      => array ( "Error durrink FTP close.",         "FTP connection closed succesfully." ),
            "delete_file"    => array ( "File can't be deleted.",           "File deleted successfully." ),
            "create_file"    => array ( "File can't be created.",           "File created successfully." ),
            "save_file"      => array ( "File content can't be saved.",     "File content saved successfully." ),
            "upload_file"    => array ( "File can't be uploaded.",          "File uploaded successfully." ),
            "duplicate_file" => array ( "File can't be duplicated.",        "File duplicated successfully." ),
            "read_file"      => array ( "File content can't be read.",      "File content read successfully." ),
            "download_file"  => array ( "File can't be downloaded.",        "File downloaded successfully." ),
            "get_file_list"  => array ( "Can't get file list from folder.", "Retrieved file list from folder." ),
            "set_permissions"=> array ( "Can't change permission.",         "Permissions changed successfully." ),
            "get_permissions"=> array ( "Can't read permission on object.", "Permission read successfully." ),
            "create_dir"     => array ( "Can't create folder.",             "Folder created sucessfully." ),
            "delete_dir"     => array ( "Can't delete folder.",             "Folder deleted successfully." ),
            "read_file_contents_base64" => array ( "Can't read file.",      "File read sucessfully." )
        );
    }
}
