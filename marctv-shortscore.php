<?php

/*
Plugin Name: MarcTV ShortScore
Plugin URI: http://marctv.de/blog/marctv-wordpress-plugins/
Description: Extends the comment fields by a review score field and alters queries.
Version:  0.8
Author:  Marc Tönsing
Author URI: marctv.de
License URI: http://www.gnu.org/licenses/gpl-2.0.html
*/

class MarcTVShortScore {

	private $version = '0.1';
	private $pluginPrefix = 'marctv-shortscore';

	public function __construct() {
		load_plugin_textdomain( 'marctv-shortscore', false, dirname( plugin_basename( __FILE__ ) ) . '/languages/' );

		$this->initComments();

		$this->initFrontend();

		$this->initSorting();
	}

	public function initSorting() {
		add_action( 'pre_get_posts', array( $this, 'my_modify_main_query' ) );
	}

	public function initFrontend() {
		add_action( 'wp_print_styles', array( $this, 'enqueScripts' ) );
		add_filter( 'get_comment_author_link', array( $this, 'comment_author_profile_link' ) );

		add_shortcode( 'list_top_authors', array( $this, 'list_top_authors' ) );
	}

	public function enqueScripts() {
		wp_enqueue_style( $this->pluginPrefix . '_style', plugins_url( false, __FILE__ ) . "/marctv-shortscore.css", false, $this->version );
		wp_enqueue_script( $this->pluginPrefix . '-js', plugins_url( false, __FILE__ ) . "/marctv-shortscore.js", array( "jquery" ), $this->version, true );

	}

	public function initComments() {
		add_filter( 'pre_comment_content', 'esc_html' );
		add_filter( 'comment_form_defaults', array( $this, 'change_comment_form_defaults' ) );
		add_action( 'comment_post', array( $this, 'save_comment_meta_data' ) );

		add_action( 'comment_post', array( $this, 'save_comment_meta_data' ) );
		add_action( 'edit_comment', array( $this, 'save_comment_meta_data' ) );
		add_action( 'deleted_comment', array( $this, 'save_comment_meta_data' ) );
		add_action( 'trashed_comment', array( $this, 'save_comment_meta_data' ) );
		add_action( 'wp_insert_comment', array( $this, 'save_comment_meta_data' ) );

		add_action( 'transition_comment_status', array( $this, 'comment_approved_check' ), 10, 3 );

		add_filter( 'preprocess_comment', array( $this, 'verify_comment_meta_data' ) );

		add_filter( 'the_content', array( $this, 'addShortScoreLink' ) );

		add_filter( 'preprocess_comment', array( $this, 'verify_comment_duplicate_email' ) );

		add_filter( 'comment_form_default_fields', array( $this, 'alter_comment_form_fields' ) );
		add_filter( 'comment_text', array( $this, 'append_score' ), 99 );
		add_filter( 'post_class', array( $this, 'add_hreview_aggregate_class' ) );
		add_filter( 'the_title', array( $this, 'add_hreview_title' ) );


	}

	public function comment_approved_check( $new_status, $old_status, $comment ) {

		if ( $old_status != $new_status ) {
			$this->save_comment_meta_data( $comment->comment_ID );
		}
	}


	public function add_hreview_aggregate_class( $classes ) {
		global $post;

		if ( get_post_type( $post->ID ) == 'game' ) {
			$classes[] = 'hreview-aggregate';
		}

		return $classes;
	}


	function add_hreview_title( $title ) {
		$id = get_the_ID();

		if ( $id >= 0 && get_post_type( $id ) == 'game' && is_single() ) {
			$title = '<span class="fn">' . $title . '</span>';
		}

		return $title;
	}


	public function list_top_authors( $atts ) {
		$query = "SELECT comment_author as name,user_id as user_id, COUNT(*) as count
		FROM wp_comments WHERE comment_approved ='1' AND comment_type ='' GROUP BY user_id ORDER BY COUNT(*) DESC LIMIT 100";

		global $wpdb;

		$result = $wpdb->get_results( $query );
		$markup = '<ol>';

		foreach ( $result as $author ) {
			if ($author->user_id > 0){
				$markup .= '<li>';
				$markup .= '<a href="' . get_author_posts_url($author->user_id) . '">'. $author->name . '</a> ';
				$markup .= 'hat ' .$author->count .' SHORTSCORES  geschrieben.';
				$markup .= '</li>';
			}
		}

		$markup .= '</ol>';

		return $markup;

	}

	public function change_comment_form_defaults( $default ) {

		$post_id = get_the_ID();

		if ( get_post_type( $post_id ) == 'game' ) {

			$markup = '<p class="comment-form-score"><label for="score">' . __( 'ShortScore 1 to 10 (e.g. 7.5)', 'marctv-shortscore' ) . '<span class="required">*</span></label><select id="score" name="score">';

			for ( $i = 1; $i <= 100; $i ++ ) {
				if ( $i == 50 ) {
					$markup .= '<option size="4"  value="' . $i / 10 . '">' . $i / 10 . '</option>';
					$markup .= '<option size="4" selected="selected" value="">?</option>';
				} else {
					$markup .= '<option size="4" value="' . $i / 10 . '">' . $i / 10 . '</option>';
				}
			}

			$commenter                  = wp_get_current_commenter();
			$req                        = get_option( 'require_name_email' );
			$aria_req                   = ( $req ? " aria-required='true'" : '' );
			$default['fields']['email'] = '<p class="comment-form-email"><label for="email">' . __( 'Email', 'marctv-shortscore' ) . ( $req ? '<span class="required">*</span>' : '' ) . '</label> ' .
			                              '<input id="email" name="email" type="text" value="' . esc_attr( $commenter['comment_author_email'] ) .
			                              '" size="30"' . $aria_req . ' /><span class="email-notice form-allowed-tags">' . __( '<strong>Warning: </strong> Your email address needs to be verified!', 'marctv-shortscore' ) . '</span></p>';
			$default['label_submit'] = __( 'Submit SHORTSCORE', 'marctv-shortscore');

			$markup .= '</select>';

			$default['must_log_in'] = '<p class="must-log-in">' . sprintf( __( 'You must be <a href="%1s">logged in</a> to post a ShortScore. <a href="%2s">Registration</a> is fast and free!', 'marctv-shortscore' ), '/login/', '/register/' ) . '</p>';

			$default['comment_notes_after'] = '<p class="form-allowed-tags" id="form-allowed-tags">' . __( 'Each account is only allow once per game.', 'marctv-shortscore' ) . '</p>';
			$default['title_reply']         = __( 'Enter your SHORTSCORE', 'marctv-shortscore' );
			$default['comment_field']       = '<p class="comment-form-comment"><label for="comment">' . __( 'Your short review text:', 'marctv-shortscore' ) . '<span class="required">*</span></label><textarea id="comment" name="comment" cols="45" rows="8" aria-required="true"></textarea></p>';
			$default['comment_field']       = $markup . $default['comment_field'];
		}

        return $default;
	}


	public static function getShortScore( $id = '' ) {
		if ( $id == '' ) {
			$id = get_the_ID();
		}


		if ( get_post_type( $id ) == 'game' ) {
			$score_count = get_post_meta( $id, 'score_count', true );
			$markup      = '<a href="' . get_permalink( $id ) . '">';
			$markup .= '<div class="rating">';

			if ( $score_count > 0 ) {

				$score_sum = get_post_meta( $id, 'score_sum', true );

				$aggregate_score = round( $score_sum / $score_count, 1 );

				$markup .= sprintf( __( '%s out of %s based on %s user reviews', 'marctv-shortscore' ),
					'<div class="average shortscore">' . $aggregate_score . '</div>',
					'<span class="best">10</span>',
					'<span class="votes">' . $score_count . '</span>'
				);

			} else {
				$markup .= '<div class="rating"><div class="average shortscore">?</div></div>';

			}

			$markup .= '</div></a>';


			return $markup;

		}

		return false;

	}


	public function addShortScoreLink( $content ) {
		$id = get_the_ID();


		if ( get_post_type( $id ) == 'game' ) {

			$markup = '<div class="game-meta">';

			$categories_list = get_the_term_list( $id, 'platform', '', ', ' );
			$markup .= sprintf( '<p class="platform"><strong><span class="screen-reader-text">%1$s </span>%2$s</strong></p>',
				_x( 'Categories', 'Used before category names.', 'twentyfifteen' ),
				$categories_list
			);

			$categories_list = get_the_term_list( $id, 'genre', '', ', ' );
			$markup .= sprintf( '<p class="genre"><span class="screen-reader-text">%1$s </span>%2$s</p>',
				_x( 'Categories', 'Used before category names.', 'twentyfifteen' ),
				$categories_list
			);

			if ( $developer_list = get_the_term_list( $id, 'developer', '', ', ' ) ) {
				$markup .= sprintf( '<span class=" developer"><span class="screen-reader-text">%1$s </span>%2$s</span>',
					_x( 'Categories', 'Used before category names.', 'twentyfifteen' ),
					$developer_list
				);
			}

			if ( $publisher_list = get_the_term_list( $id, 'publisher', '', ', ' ) ) {
				$markup .= sprintf( ' &mdash; <span class=" publisher"><span class="screen-reader-text">%1$s </span>%2$s</span>',
					_x( 'Categories', 'Used before category names.', 'twentyfifteen' ),
					$publisher_list
				);
			}

			$markup .= '</div>';

			$markup .= '<p class="shortscore-submit ">' . sprintf( __( '<a class="btn" href="%s">Submit ShortScore</a>', 'marctv-shortscore' ), esc_url( get_permalink( $id ) . '#respond' ) ) . '</p>';

			return $content . $markup;
		}

		return $content;
	}

	public function save_comment_meta_data( $comment_id ) {
		$comment = get_comment( $comment_id );

		if ( get_post_type( $comment->comment_post_ID ) == 'game' ) {
			add_comment_meta( $comment_id, 'score', $_POST['score'], true );
			$this->save_ratings_to_post( $comment->comment_post_ID );
		}
	}


	public function updateScoreOnPost( $comment_id ) {
		if ( $comment_id >= 0 ) {
			$comment = get_comment( $comment_id );
			$this->save_ratings_to_post( $comment->comment_post_ID );
		}

	}

	public function save_ratings_to_post( $post_ID ) {
		$args = array(
			'status'  => 'approve',
			'post_id' => $post_ID,
		);

		$score_sum   = 0;
		$score_count = 0;

		$comments = get_comments( $args );

		foreach ( $comments as $comment ) :
			$comment->comment_ID;
			$meta_score = get_comment_meta( $comment->comment_ID, 'score', true );

			if ( $meta_score ) {
				$score_count ++;
				$score_sum = $score_sum + $meta_score;
			}

		endforeach;

		if ( $score_sum > 0 && $score_count > 0 ) {
			$score_value = round( $score_sum / $score_count, 1 );

			add_post_meta( $post_ID, 'score_value', $score_value, true ) || update_post_meta( $post_ID, 'score_value', $score_value );
			add_post_meta( $post_ID, 'score_sum', $score_sum, true ) || update_post_meta( $post_ID, 'score_sum', $score_sum );
			add_post_meta( $post_ID, 'score_count', $score_count, true ) || update_post_meta( $post_ID, 'score_count', $score_count );
		} else {
			add_post_meta( $post_ID, 'score_value', 0, true ) || update_post_meta( $post_ID, 'score_value', 0 );
			add_post_meta( $post_ID, 'score_sum', 0, true ) || update_post_meta( $post_ID, 'score_sum', 0 );
			add_post_meta( $post_ID, 'score_count', 0, true ) || update_post_meta( $post_ID, 'score_count', 0 );
		}
	}


	public function verify_comment_meta_data( $commentdata ) {
		global $post;

		if ( get_post_type( $post->ID ) == 'game' ) {
			$score = $_POST['score'];

			if ( empty( $score ) ) {
				wp_die( __( 'Error: please fill the required field (score).', 'marctv-shortscore' ) );
			}

			if ( $score < 0 || $score > 10 ) {
				wp_die( __( 'Error: enter a rating smaller than 10 and greater than 0 for (score).', 'marctv-shortscore' ) );
			}

		}

		return $commentdata;
	}

	public function verify_comment_duplicate_email( $commentdata ) {
		global $post;

		$email = $commentdata["comment_author_email"];

		$args = array(
			'status'  => 'approve',
			'post_id' => $post->ID
		);

		$comments = get_comments( $args );

		foreach ( $comments as $comment ) :
			if ( $email == $comment->comment_author_email ) {
				wp_die( __( 'Sorry, this account has already submitted a shortscore for this game.', 'marctv-shortscore' ) );
			}
		endforeach;

		return $commentdata;
	}


	public function alter_comment_form_fields( $default ) {

		global $post;

		if ( get_post_type( $post->ID ) == 'game' ) {
			$default['url'] = '';  //removes website field
		}

		return $default;
	}


	public function append_score( $comment_text ) {
		$comment_ID = get_comment_ID();

		$comment = get_comment( $comment_ID );

		$cid = $comment->user_id;
		$pid = $comment->comment_post_ID;

		if ( is_author( $cid ) ) {
			$comment_text = '<h4><a href="' . get_comment_link( $comment_ID ) . '">' . get_the_title( $comment->comment_post_ID ) . '</a></h4>';
		}

		$score = get_comment_meta( get_comment_ID(), 'score', true );

		if ( ! empty( $score ) ) {
			return $comment_text . '<a href="' . get_comment_link( $comment_ID ) . '"><div class="rating shortscore">' . $score . '</div></a>';
		}

		return $comment_text;
	}

	public function my_modify_main_query( $query ) {
		if ( $query->is_home() && $query->is_main_query() ) { // Run only on the homepage
			$query->set( 'post_type', array( 'game', 'post' ) );
		}

		if ( ! is_admin() ) {
			if ( $query->is_archive() && $query->is_main_query() ) {
				$query->set( 'post_type', array( 'game', 'post' ) );
				$query->set( 'meta_key', 'score_value' );
				$query->set( 'orderby', 'meta_value_num date' );
				$query->set( 'order', 'DESC' );
			}
		}
	}

	public function comment_author_profile_link() {
		/* Get the comment author information */

		global $comment;
		$comment_ID = $comment->user_id;
		$author     = get_comment_author( $comment_ID );
		$url        = get_comment_author_url( $comment_ID );

		/* Check if commenter is registered or not */
		switch ( $comment_ID == 0 ) {

			case true:
				/* Unregistered commenter */

				if ( empty( $url ) || 'http://' == $url ) {
					$return = $author;
				} else {
					$return = "<a href='$url' rel='external nofollow' class='url' target='_blank'>$author</a>";
				}

				break;

			case false:
				/* Registered Commenter */

				$registeredID = get_userdata( $comment_ID );
				$authorName   = $registeredID->display_name;
				$authorID     = $registeredID->ID;

				/* Author+ with Posts */

				$return = '<a href="' . get_author_posts_url( $authorID ) . '">' . $authorName . '</a>';


				break;
		}

		return $return;
	}
}


/**
 * Initialize plugin.
 */
new MarcTVShortScore();
