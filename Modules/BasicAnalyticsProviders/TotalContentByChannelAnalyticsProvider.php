<?php
namespace Swiftriver\AnalyticsProviders;
include_once(\dirname(__FILE__)."/BaseAnalyticsClass.php");
class TotalContentByChannelAnalyticsProvider
    extends BaseAnalyticsClass
    implements \Swiftriver\Core\Analytics\IAnalyticsProvider
{
    /**
     * Function that should return the name of the
     * given AnalyticsProvider.
     *
     * @return string
     */
    public function ProviderType()
    {
        return "TotalContentByChannelAnalyticsProvider";
    }

    /**
     * Function that when implemented by a derived
     * class should return an object that can be
     * json encoded and returned to the UI to
     * provide analytical information about the
     * underlying data.
     *
     * @param \Swiftriver\Core\Analytics\AnalyticsRequest $parameters
     * @return \Swiftriver\Core\Analytics\AnalyticsRequest
     */
    public function ProvideAnalytics($request)
    {
        $logger = \Swiftriver\Core\Setup::GetLogger();

        $logger->log("Swiftriver::AnalyticsProviders::AccumulatedContentOverTimeAnalyticsProvider::ProvideAnalytics [Method Invoked]", \PEAR_LOG_DEBUG);

        switch ($request->DataContextType)
        {
            case "\Swiftriver\Core\Modules\DataContext\MySql_V2\DataContext":
                return $this->mysql_analytics($request);
            break;
            case "\Swiftriver\Core\Modules\DataContext\Mongo_V1\DataContext":
                return $this->mongo_analytics($request);
            break;
            default :
                return null;
        }
    }

    function mysql_analytics($request) {
        $logger = \Swiftriver\Core\Setup::GetLogger();

        $parameters = $request->Parameters;

        $yearDay = (int) \date('z');

        $timeLimit = 5;

        if(\is_array($parameters))
            if(\key_exists("TimeLimit", $parameters))
                $timeLimit = (int) $parameters["TimeLimit"];

        $date = \strtotime("-$timeLimit days");

        $sql =
            "SELECT
                count(c.id) as numberofcontentitems,
                ch.id as channelId,
                ch.type as channelType,
                ch.subType as channelSubType
            FROM
                SC_Content c JOIN SC_Sources s ON c.sourceId = s.id
                JOIN SC_Channels ch ON s.channelId = ch.id
            WHERE
                c.date > $date
            GROUP BY
                channelId";

        try
        {
            $db = parent::PDOConnection($request);

            if($db == null)
                return $request;

            $statement = $db->prepare($sql);

            $result = $statement->execute();

            if($result == false)
            {
                $logger->log("Swiftriver::AnalyticsProviders::TotalContentByChannelAnalyticsProvider::ProvideAnalytics [An exception was thrown]", \PEAR_LOG_ERR);

                $errorCollection = $statement->errorInfo();

                $logger->log("Swiftriver::AnalyticsProviders::TotalContentByChannelAnalyticsProvider::ProvideAnalytics [" . $errorCollection[2] . "]", \PEAR_LOG_ERR);

                return $request;
            }

            $request->Result = array();

            foreach($statement->fetchAll() as $row)
            {
                $entry = array(
                    "numberofcontentitems" => $row["numberofcontentitems"],
                    "channelId" => $row["channelId"],
                    "channelType" => $row["channelType"],
                    "channelSubType" => $row["channelSubType"]);

                $request->Result[] = $entry;
            }
        }
        catch(\PDOException $e)
        {
            $logger->log("Swiftriver::AnalyticsProviders::TotalContentByChannelAnalyticsProvider::ProvideAnalytics [An exception was thrown]", \PEAR_LOG_ERR);

            $logger->log("Swiftriver::AnalyticsProviders::TotalContentByChannelAnalyticsProvider::ProvideAnalytics [$e]", \PEAR_LOG_ERR);
        }

        $logger->log("Swiftriver::AnalyticsProviders::TotalContentByChannelAnalyticsProvider::ProvideAnalytics [Method finished]", \PEAR_LOG_DEBUG);

        return $request;
    }

    function mongo_analytics($request) {
        $logger = \Swiftriver\Core\Setup::GetLogger();

        $parameters = $request->Parameters;

        $yearDay = (int) \date('z');

        $timeLimit = 5;

        if(\is_array($parameters))
            if(\key_exists("TimeLimit", $parameters))
                $timeLimit = (int) $parameters["TimeLimit"];

        $date = \strtotime("-$timeLimit days");

        $channel_array = array();

        $request->Result = null;

        try
        {
            $db_content = parent::PDOConnection($request);
            $db_sources = parent::PDOConnection($request);
            $db_channels = parent::PDOConnection($request);

            $db_content->where(array("date" => array('$gte' => $date)));
            $content_items = $db_content->get("content");

            $logger->log("Swiftriver::AnalyticsProviders::TotalContentByChannelAnalyticsProvider::ProvideAnalytics [Selected date: $date]", \PEAR_LOG_INFO);

            $logger->log("Swiftriver::AnalyticsProviders::TotalContentByChannelAnalyticsProvider::ProvideAnalytics [".\count($content_items)." number of content items retrieved]", \PEAR_LOG_INFO);

            $channel_array = array();

            foreach($content_items as $content_item) {
                $source_id = $content_item["source"]["id"];
                $source_items = $db_sources->get_where("sources", array("id" => $source_id));

                $logger->log("Swiftriver::AnalyticsProviders::TotalContentByChannelAnalyticsProvider::ProvideAnalytics [".\count($source_items)." number of source items retrieved]", \PEAR_LOG_INFO);

                foreach($source_items as $source_item) {
                    $channel_id = $source_item["channelId"];
                    if(!\in_array($channel_id, $channel_array)) {
                        $channel_array[$channel_id] = array();
                    }

                    $channels = $db_channels->get_where("channels", array("id" => $channel_id));

                    $logger->log("Swiftriver::AnalyticsProviders::TotalContentByChannelAnalyticsProvider::ProvideAnalytics [".\count($channels)." number of channels retrieved]", \PEAR_LOG_INFO);

                    foreach($channels as $channel) {
                        $channel_array[$channel_id]["channelId"] = $channel_id;
                        $channel_array[$channel_id]["channelName"] = $channel["name"];

                        if(!\in_array($channel_id, $channel_array[$channel_id]["numberOfContentItems"])) {
                            $channel_array[$channel_id]["numberOfContentItems"] = 1;
                        }
                        else {
                            $channel_array[$channel_id]["numberOfContentItems"] += 1;
                        }

                        $channel_array[$channel_id]["channelType"] = $content_item["source"]["type"];
                        $channel_array[$channel_id]["channelSubType"] = $content_item["source"]["subType"];
                    }
                }
            }
        }
        catch(\MongoException $e) {
            $logger->log("Swiftriver::AnalyticsProviders::ContentByChannelOverTimeAnalyticsProvider::ProvideAnalytics [An exception was thrown]", \PEAR_LOG_ERR);

            $logger->log("Swiftriver::AnalyticsProviders::ContentByChannelOverTimeAnalyticsProvider::ProvideAnalytics [$e]", \PEAR_LOG_ERR);
        }

        foreach($channel_array as $channel_array_item) {
            if($request->Result == null) {
                $request->Result = array();
            }

            $request->Result[] = $channel_array_item;
        }

        return $request;
    }

    /**
     * Function that returns an array containing the
     * fully qualified types of the data content's
     * that the deriving Analytics Provider can work
     * against
     *
     * @return string[]
     */
    public function DataContentSet()
    {
        return array("\Swiftriver\Core\Modules\DataContext\MySql_V2\DataContext");
    }
}
?>
