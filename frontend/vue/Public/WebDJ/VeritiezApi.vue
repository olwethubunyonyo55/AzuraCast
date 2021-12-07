<template>
    <div class="list-group list-group-flush" v-if="songs.length > 0">
        <a href="#" class="list-group-item list-group-item-action flex-column align-items-start"
            v-for="(rowFile, rowIndex) in songs" v-bind:class="{ active: rowIndex == fileIndex }"
            v-on:click.prevent="play({ fileIndex: rowIndex })">
            <div class="d-flex w-100 justify-content-between">
                <h5 class="mb-0">{{
                        rowFile.title ? rowFile.title : lang_unknown_title
                    }}</h5>
                <small class="pt-1">{{ rowFile.song.length | prettifyTime }}</small>
            </div>
            <p class="mb-0">{{ rowFile.artist ? rowFile.artist : lang_unknown_artist }}</p>
        </a>
    </div>
</template>
<script>

import axios from "axios";
import 'regenerator-runtime/runtime';
import Cookies from 'js-cookie';
import track from './Track.js';


var csrfCookie = Cookies.get('csrftoken'); 
console.log('csrf cookie: ', csrfCookie); // set to undefined

axios.defaults.xsrfCookieName = 'csrftoken';
axios.defaults.xsrfHeaderName = 'X-CSRFToken';
export default {
    name: 'VeritiezSongs',
    extends: track,
    data() { 
        return {
            'fileIndex': -1,
            songs: []
        };
    },
    async mounted() {
        try {
            var result = await axios({
                method: "POST",
                // xstfCookieName: 'csrftoken',
                // xsrfHeaderName: 'X-CSRFToken',
                // headers: {
                //             'X-CSRFToken': csrfCookie,
                //         }, 
                url: "http://localhost:8000/graphql/",
                data: {
                    query: `
                    {
                        allAudio {
                          id,
                          title,
                          song
                        }
                      }
                    `
                }
            });
            this.song = result.data.data.allAudio;
        } catch (error) {
            console.error(error);
        }
    },
    filters: {
        prettifyTime (time) {
            if (typeof time === 'undefined') {
                return 'N/A';
            }

            var hours = parseInt(time / 3600);
            time %= 3600;
            var minutes = parseInt(time / 60);
            var seconds = parseInt(time % 60);

            if (minutes < 10) {
                minutes = '0' + minutes;
            }
            if (seconds < 10) {
                seconds = '0' + seconds;
            }

            if (hours > 0) {
                return hours + ':' + minutes + ':' + seconds;
            } else {
                return minutes + ':' + seconds;
            }
        }
    },
    computed: {
        lang_unknown_title () {
            return this.$gettext('Unknown Title');
        },
        lang_unknown_artist () {
            return this.$gettext('Unknown Artist');
        }
    },
    methods: {
        play (options) {
            this.resumeStream();

            if (this.paused) {
                this.togglePause();
                return;
            }

            this.stop();

            if (!(this.song = this.selectFile(options))) {
                return;
            }

            this.prepare();

            return this.getStream().createFileSource(this.song, this, (source) => {
                var ref1;
                this.source = source;
                this.source.connect(this.destination);
                if (this.source.duration != null) {
                    this.duration = this.source.duration();
                } else {
                    if (((ref1 = this.song.audio) != null ? ref1.length : void 0) != null) {
                        this.duration = parseFloat(this.song.audio.length);
                    }
                }

                this.source.play(this.song);

                this.$root.$emit('metadata-update', {
                    title: this.song.metadata.title,
                    artist: this.song.metadata.artist
                });

                this.playing = true;
                this.paused = false;
            });
        },

        // selectFile (options = {}) {
        //     if (this.songs.length === 0) {
        //         return;
        //     }

            // if (options.fileIndex) {
            //     this.fileIndex = options.fileIndex;
            // } else {
            //     this.fileIndex += options.backward ? -1 : 1;
            //     if (this.fileIndex < 0) {
            //         this.fileIndex = this.songs.length - 1;
            //     }

            //     if (this.fileIndex >= this.songs.length) {
            //         if (options.isAutoPlay && !this.loop) {
            //             this.fileIndex = -1;
            //             return;
            //         }

            //         if (this.fileIndex < 0) {
            //             this.fileIndex = this.songs.length - 1;
            //         } else {
            //             this.fileIndex = 0;
            //         }
            //     }
            // }
           /*  return this.songs[this.fileIndex];
        } */
    }
};
</script>