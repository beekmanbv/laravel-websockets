<?php

namespace BeyondCode\LaravelWebSockets\API;

use BeyondCode\LaravelWebSockets\Channels\Channel;
use BeyondCode\LaravelWebSockets\Facades\StatisticsCollector;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use stdClass;
use Symfony\Component\HttpKernel\Exception\HttpException;

class FetchChannels extends Controller
{
    /**
     * Handle the incoming request.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\Response
     */
    public function __invoke(Request $request)
    {
        $attributes = [];

        if ($request->has('info')) {
            $attributes = explode(',', trim($request->info));

            if (in_array('user_count', $attributes) && ! Str::startsWith($request->filter_by_prefix, 'presence-')) {
                throw new HttpException(400, 'Request must be limited to presence channels in order to fetch user_count');
            }

//            if (in_array('socket_count', $attributes) &&  $request->has('starts_with')) {
//                if (! Str::startsWith($request->starts_with, ['presence-', 'private-', 'screencast-'])) {
//                    throw new HttpException(400, 'Requests must be limited to presence, private and screencast channels in order to fetch channel data');
//                }
//            }
        }

        if (in_array('socket_count', $attributes)) {
            return $this->channelManager
                ->getChannelSockets($request->appId)
                ->then(function ($channels) use ($request) {
                    if (count($channels)) {

                        StatisticsCollector::channelChecked($request->appId);

                        if ($request->has('starts_with')) {
                            $channels = $channels->filter(function ($channel, $channelName) use ($request) {
                                return Str::startsWith($channelName, $request->starts_with);
                            });
                        }

                        $channelNames = $channels->keys()->all();

                        return $this->channelManager
                            ->getChannelsSocketsCount($request->appId, $channelNames)
                            ->then(function ($counts) use ($channels) {
                                return [
                                    'channels' => $counts ?: new stdClass,
                                ];
                            });
                    }
                    return [];
                });
        }

        return $this->channelManager
            ->getGlobalChannels($request->appId)
            ->then(function ($channels) use ($request, $attributes) {
                $channels = collect($channels)->keyBy(function ($channel) {
                    return $channel instanceof Channel
                        ? $channel->getName()
                        : $channel;
                });

                if ($request->has('filter_by_prefix')) {
                    $channels = $channels->filter(function ($channel, $channelName) use ($request) {
                        return Str::startsWith($channelName, $request->filter_by_prefix);
                    });
                }

                $channelNames = $channels->map(function ($channel) {
                    return $channel instanceof Channel
                        ? $channel->getName()
                        : $channel;
                })->toArray();

                return $this->channelManager
                    ->getChannelsMembersCount($request->appId, $channelNames)
                    ->then(function ($counts) use ($channels, $attributes) {
                        $channels = $channels->map(function ($channel) use ($counts, $attributes) {
                            $info = new stdClass;

                            $channelName = $channel instanceof Channel
                                ? $channel->getName()
                                : $channel;

                            if (in_array('user_count', $attributes)) {
                                $info->user_count = $counts[$channelName];
                            }

                            return $info;
                        })->sortBy(function ($content, $name) {
                            return $name;
                        })->all();

                        return [
                            'channels' => $channels ?: new stdClass,
                        ];
                    });
            });
    }
}
