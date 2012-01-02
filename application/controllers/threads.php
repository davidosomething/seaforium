<?php
class Threads extends Controller {

  var $meta;

  function __construct()
  {
    parent::__construct();

    // load up some external help
    $this->load->helper(array('date', 'url'));
    $this->load->library('pagination');
    $this->load->model('thread_dal');

    // set all this so we dont have to continually call functions through session
    $this->meta = array(
      'user_id' => (int) $this->session->userdata('user_id'),
      'username' => $this->session->userdata('username'),
      'hide_enemy_posts' => $this->session->userdata('hide_enemy_posts'),
      'threads_shown' => $this->session->userdata('threads_shown') === false ? 50 : $this->session->userdata('threads_shown')
    );
  }

  function index($pagination = 0, $filter = '', $ordering = '', $dir = 'desc', $whostarted = '')
  {
    // uncomment the following line you if broke something but you can't figure out what.
    // $this->output->enable_profiler(TRUE);

    $args = (object)array(
      'pagination' => (int) $pagination,
      'filter' => strtolower($filter),
      'ordering' => strtolower($ordering),
      'dir' => strtolower($dir),
      'whostarted' => strtolower($whostarted)
    );

    if ($args->filter == 'started' && $args->whostarted == '')
      $args->whostarted = strtolower($this->meta['username']);

    $this->load->model('threadsmodel');

    $this->threadsmodel->meta = $this->meta;
    $this->threadsmodel->args = $args;

    // process thread information
    $this->threadsmodel->get_threads();

    // init the pagination library
    $this->pagination->initialize(array(
      'base_url' => '/p/',
      'total_rows' => $this->threadsmodel->thread_count,
      'uri_segment' => '2',
      'num_links' => 1,
      'per_page' => $this->meta['threads_shown'],
      'suffix' => $this->threadsmodel->url_suffix
    ));

    // load up the header
    $this->load->view('shared/header');

    // end of threads
    $end = min(array($args->pagination + $this->meta['threads_shown'], $this->threadsmodel->thread_count));

    $pages = $this->pagination->create_links() . '<span class="paging-text">' .
      ($args->pagination + 1) . ' - ' . $end . ' of ' . $this->threadsmodel->thread_count . ' Threads</span>';

    $this->load->view('threads', array(
      'title' => $this->thread_dal->get_front_title(),
      'thread_result' => $this->threadsmodel->thread_results,
      'pagination' => $pages,
      'tab_links' => strlen($args->filter) > 0 ? '/f/'.$args->filter.'/' : '/o/',
      'tab_orders' => array(
        'started' => $args->ordering == 'started' && $args->dir == 'desc' ? 'asc' : 'desc',
        'latest' => $args->ordering == 'latest' && $args->dir == 'desc' ? 'asc' : 'desc',
        'posts' => $args->ordering == 'posts' && $args->dir == 'desc' ? 'asc' : 'desc',
        'startedby' => $args->whostarted
      ),
      'favorites' => explode(',', $this->thread_dal->get_favorites($this->meta['user_id'])),
      'hidden_threads' => explode(',', $this->thread_dal->get_hidden($this->meta['user_id']))
    ));

    $this->load->view('shared/footer');
  }

  public function find($search_terms = '', $pagination = 0, $filter = '', $ordering = '', $dir = 'desc', $whostarted = '')
  {
		/* redirect('/'); */
		/* exit(); */
    // uncomment the following line you if broke something but you can't figure out what.
    // $this->output->enable_profiler(TRUE);

    $this->load->library('SphinxClient');

    $args = (object)array(
      'pagination' => (int) $pagination,
      'filter' => '',
      'ordering' => '',
      'dir' => '',
      'whostarted' => '',
      'search_terms' => $search_terms
    );

    $this->load->model('threadsmodel');

    $this->threadsmodel->meta = $this->meta;
    $this->threadsmodel->args = $args;

    $s = new SphinxClient();
    $s->SetServer("localhost", 3312);
    $s->SetMatchMode(SPH_MATCH_EXTENDED2);
    $s->SetMaxQueryTime(1);
    $s->SetLimits($args->pagination, ($this->meta['threads_shown'] + 1));

    $result = $s->query($search_terms);

    $final = $result['total_found'] > 0
      ? implode(',', array_keys($result['matches']))
      : '';

    // process thread information
    $this->threadsmodel->get_threads($final);

    // init the pagination library
    $this->pagination->initialize(array(
      'base_url' => 'find/'. $search_terms .'/p/',
      'total_rows' => $result['total_found'],
      'uri_segment' => '4',
      'num_links' => 1,
      'per_page' => $this->meta['threads_shown'],
      'suffix' => $this->threadsmodel->url_suffix
    ));

    // load up the header
    $this->load->view('shared/header');

    // end of threads
    $end = min(array($args->pagination + $this->meta['threads_shown'], $result['total_found']));

    $pages = $this->pagination->create_links() . '<span class="paging-text">' .
      ($args->pagination + 1) . ' - ' . $end . ' of ' . $result['total_found'] . ' Threads</span>';

    $this->load->view('threads', array(
      'title' => (object)array(
        'username' => 'Yayhooray',
        'title_text' => "Searching for: \"$search_terms\""
       ),
      'thread_result' => $this->threadsmodel->thread_results,
      'pagination' => $pages,
      'tab_links' => strlen($args->filter) > 0 ? '/f/'.$args->filter.'/' : '/o/',
      'tab_orders' => array(
        'started' => $args->ordering == 'started' && $args->dir == 'desc' ? 'asc' : 'desc',
        'latest' => $args->ordering == 'latest' && $args->dir == 'desc' ? 'asc' : 'desc',
        'posts' => $args->ordering == 'posts' && $args->dir == 'desc' ? 'asc' : 'desc',
        'startedby' => $args->whostarted
      ),
      'favorites' => explode(',', $this->thread_dal->get_favorites($this->meta['user_id'])),
      'hidden_threads' => explode(',', $this->thread_dal->get_hidden($this->meta['user_id']))
    ));

    $this->load->view('shared/footer');
  }
}

/* End of file welcome.php */
/* Location: ./application/controllers/welcome.php */