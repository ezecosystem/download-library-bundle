<?php

namespace xrow\DownloadLibraryBundle\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\Controller;
use eZ\Publish\API\Repository\Values\Content\Query;
use eZ\Publish\API\Repository\Values\Content\Query\Criterion;
use ZipArchive;

class DownloadController extends Controller
{
    function __construct()
    {

    }

    public function startDownloadAction($source_node_id, $current_subtree_mod_ts)
    {
        if( is_numeric($source_node_id) AND is_numeric($current_subtree_mod_ts) )
        {
            $file_prefix = $this->container->getParameter('file_prefix');
            $check_existance_how = $this->container->getParameter('check_existance_how');
            $destination_path = $this->container->getParameter('destination_path');
            $var_root = $this->container->getParameter('var_root');
            $depth = $this->container->getParameter('depth');

            $file = new DownloadController();
            $file->name = $file_prefix . "_" . $source_node_id . "_". $current_subtree_mod_ts . ".zip";
            $file->path = $destination_path . $file->name;
            $file->source_node_id = $source_node_id;
            $file->search_pattern = $file_prefix . "_" . $source_node_id . "_*";
            $file->folder_location = $destination_path;

            $repository = $this->container->get( 'ezpublish.api.repository' );

            //next line should be removed once we login on new stack only and dont mix legacy with 5.x anymore
            $repository->setCurrentUser( $repository->getUserService()->loadUserByLogin( 'admin' ) );

            $source_node = $repository->getLocationService()->loadLocation( $file->source_node_id );

            if ( is_object($source_node) )
            {
                $file->nicename = trim($source_node->contentInfo->name) . ".zip";
                if( $file->exists($check_existance_how) )
                {
                    $file->downloadFile();
                }
                else
                {
                    
                    $white_list_classes = $this->container->getParameter('white_list_classes');
                    $white_list_fields = $this->container->getParameter('white_list_fields');
                    $image_list = array();

                    $query = new Query();
                    $query->criterion = new Criterion\LogicalAnd(array(
                        new Criterion\Subtree( $source_node->pathString ),
                        new Criterion\ContentTypeIdentifier( $white_list_classes ),
                        new Criterion\Visibility( Criterion\Visibility::VISIBLE ),
                        new Criterion\Depth( Criterion\Operator::EQ, $source_node->depth+1),
                    ) );

                    $searchHits = $repository->getSearchService()->findContent( $query )->searchHits;

                    if(count($searchHits) >= 1)
                    {
                        
                        foreach( $searchHits as $searchHit )
                        {
                            foreach ( $white_list_fields as $field_name)
                            {
                                if( isset($searchHit->valueObject->fields[$field_name]) )
                                {
                                    $attribute_data = $searchHit->valueObject->fields[$field_name];
                                    $language_code = $source_node->contentInfo->mainLanguageCode;
                                    if( isset($attribute_data[$language_code]->uri) AND $attribute_data[$language_code]->uri != "" )
                                    {
                                        $image_list[] = $var_root . $attribute_data[$language_code]->uri;
                                    }
                                }
                            }
                        }
                        $file->createFile($image_list);
                        $file->removeOldFile();
                        $file->downloadFile();
                    }
                    else
                    {
                        #TODO: Needs to be improved + error LOG
                        return $this->render('xrowDownloadLibraryBundle:Default:error.html.twig', array('ErrorCode' => 'nothing to download'));
                    }
                }
            }
        }
        #TODO: Needs to be improved + error LOG
        return $this->render('xrowDownloadLibraryBundle:Default:error.html.twig', array('ErrorCode' => 'invalid URL'));
    }

    /* "where parameter" explanation:
        db = checks inside the database if the file exists
        hdd = checks if the file exists on the hard disk
        all = checks both options
    */
    public function exists( $where = "all" )
    {
        $found = false;
        $file_name = $this->name;

        if ($where == "hdd" OR $where == "all")
        {
            //check on filesystem
            if ( file_exists( $this->path ) )
            {
                $found = true;
            }
        }

        /*
        #not needed since we dont use a clustered system
        if ($where == "db" OR $where == "all")
        {
            //TODO check in dfstable here
            if( count >= 1 )
            {
                $this->absolut_path = $file_name;
                $found = true;
            }
        }
        */
        return $found;
    }

    public function createFile( $image_list = array() )
    {
        if( count($image_list) >= 0 )
        {
            #"register all images in ezdfs" is not needed since we dont use clustered system
            $zip = new ZipArchive;
            $res = $zip->open( $this->path, ZipArchive::CREATE );
            if ($res === true)
            {
                foreach( $image_list as $image)
                {
                    #adds file to the zip archive on first param, on second parameter only add the "name" so we dont have the whole folder structure
                    $zip->addFile($image, substr($image,strrpos($image,'/') + 1));
                }
                $zip->close();
            } else {
                #TODO: Is not displayed, needs to be improved + error LOG
                return $this->render('xrowDownloadLibraryBundle:Default:error.html.twig', array('ErrorCode' => 'ZIP Archive Error Code:' . $res));
            }
        }
    }

    public function removeOldFile()
    {
        #"expiring file in dfs table" is not needed since we dont use clustered system
        if( is_dir($this->folder_location) )
        {
            $files = array();
            #get all files which match to this node
            foreach (glob($this->folder_location . $this->search_pattern . "*") as $filename)
            {
                $files[] = $filename;
            }
            #sort the files by creation date
            usort($files, create_function('$a,$b', 'return filemtime($a) - filemtime($b);'));
            #remove the newest file from array to protect it
            array_pop($files);
            #remove all remaining files which are expired
            foreach ($files as $file)
            {
                if( is_readable($file) )
                {
                    unlink($file);
                }
                else
                {
                    #TODO: error message cannot delete file: $file
                }
            }
        }
    }

    public function downloadFile()
    {
        // http headers for zip downloads
        header("Pragma: public");
        header("Expires: 0");
        header("Cache-Control: must-revalidate, post-check=0, pre-check=0");
        header("Cache-Control: public");
        header("Content-Description: File Transfer");
        header("Content-type: application/octet-stream");
        header("Content-Disposition: attachment; filename=\"".$this->nicename."\"");
        header("Content-Transfer-Encoding: binary");
        header("Content-Length: ".filesize($this->path));
        ob_end_flush();
        @readfile($this->path);
    }
}
