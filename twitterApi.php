<?php 
require_once "config.php";

# set new line
if(is_null($_SERVER['HTTP_HOST'])){
  define("NW", PHP_EOL);
}else{
  define("NW", "<br/>");
}

function tof($var){
  if($var===TRUE){
    return 1;
  }else{
    return 0;
  }
}

/*
* MAIS INFOS: https://developer.twitter.com/en/docs/twitter-api/v1/tweets/timelines/api-reference/get-statuses-user_timeline
* $BEARER_TOKEN https://developer.twitter.com/en/docs/authentication/oauth-2-0/bearer-tokens
* curl -u "$API_KEY:$API_SECRET_KEY" \
* --data 'grant_type=client_credentials' \
* 'https://api.twitter.com/oauth2/token'
*
* debug = 0 (Mostrar Erros)
* debug = 1 (Mostrar Processos)
*/

class Twitter_API
{
  private $BEARER_TOKEN = "AAAAAAAAAAAAAAAAAAAAAMcA6QAAAAAAcSUOLat7Op7PjAfF%2Fl9EcRKxch4%3DjgWAeD7CFO0TsD8cigYe0Z4rL3JmRRkFetvzq8vOwSh3KIZwks";
  private $postsNumber = 10;
  private $debug = 1;
  private $usersValidated = array();


  public function getUserPosts($nickname){
    global $link;
    echo NW.NW."Pegando Posts @".$nickname;
    // echo  ($this->debug==1)? NW."Pegando Posts @".$nickname : "";

    $lastPostQueryParam = self::getLastPostID($nickname);

    $url = 'https://api.twitter.com/1.1/statuses/user_timeline.json?screen_name='.$nickname.'&count='.$this->postsNumber.$lastPostQueryParam.'&exclude_replies=true&include_rts=false&tweet_mode=extended';
    echo  ($this->debug==1)? NW."URL: ".$url : "";
    $results = self::curl($url);
    $contPosts=0;
    foreach ($results as $result) {
      // Verifica Users
      if(!in_array($result['user']['id'], $this->usersValidated)){
        echo  ($this->debug==1)? NW."Não está no array de Users: ".$result['user']['id']."": "";
        self::verifyUser($result['user']);
      }

      // trata os Posts
      $sql = 'SELECT * FROM TwitterPosts WHERE id ='.$result['id'].';';
      $res = mysqli_query($link, $sql);
      if(mysqli_num_rows($res)==0){
        // Trata os POSTS
        $sensitive = (isset($result['possibly_sensitive']))? tof($result['possibly_sensitive']) : '0';

        $ins = 'INSERT INTO TwitterPosts (
          id,
          idUser,
          created_at,
          created_at_SP,
          texto,
          possibly_sensitive,
          lang,
          dataHoraSave
          ) VALUES (
            '.$result['id'].',
            '.$result['user']['id'].',
            "'.$result['created_at'].'",
            "'.self::dateTimeConverter($result['created_at']).'",
            "'.addslashes($result['full_text']).'",
            '.$sensitive.',
            "'.$result['lang'].'",
            NOW()
          );';
          $q = mysqli_query($link, $ins);
          if($q){
            // Verifica se tem alguma media
            if(count($result['entities']['urls'])>0){
              self::saveUrls($result['entities']['urls'], $result['id']);
            }

            if(isset($result['extended_entities']['media']) && count($result['extended_entities']['media'])>0){
              self::saveMedias($result['extended_entities']['media'], $result['id']);
            }



            echo  ($this->debug==1)? NW."Post ".$result['id'] : "";
          }else{
            $ins = "INSERT INTO TwitterPostsErrors (id) VALUES (".$result['id'] .");";
            mysqli_query($link, $ins);
            echo NW.NW."Erro ao salvar Post ".$result['id'].". ".NW."QUERY: ".$ins." ".NW."Error:". mysqli_error($link);
          }

      }else{
        echo  ($this->debug==1)? NW."Post já salvo ".$result['id']."": "";
      }
      $contPosts++;
    }
    
    echo NW.NW."Posts Salvos: ".$contPosts;
  }

  private function saveMedias($medias, $id){
    global $link;

    foreach ($medias as $media) {
      switch ($media['type']) {
        case 'photo':
          $ins = 'INSERT INTO TwitterPosts_Media (
            id,
            idPost,
            `type`,
            url,
            media_url,
            media_url_https,
            indices,
            display_url,
            expanded_url
          ) VALUES (
            '.$media['id'].',
            '.$id.',
            "'.$media['type'].'",
            "'.$media['url'].'",
            "'.$media['media_url'].'",
            "'.$media['media_url_https'].'",
            "'.implode(',', $media['indices']).'",
            "'.$media['display_url'].'",
            "'.$media['expanded_url'].'"
          );';
          $q = mysqli_query($link, $ins);
          break;
        
        case 'animated_gif':
        case 'video':
          $duration_millis = (isset($media['video_info']['duration_millis']))? $media['video_info']['duration_millis'] : 'NULL';

          $ins = 'INSERT INTO TwitterPosts_Media (
            id,
            idPost,
            `type`,
            url,
            media_url,
            media_url_https,
            indices,
            display_url,
            expanded_url,
            video_aspect_ratio,
            video_duration_millis
          ) VALUES (
            '.$media['id'].',
            '.$id.',
            "'.$media['type'].'",
            "'.$media['url'].'",
            "'.$media['media_url'].'",
            "'.$media['media_url_https'].'",
            "'.implode(',', $media['indices']).'",
            "'.$media['display_url'].'",
            "'.$media['expanded_url'].'",
            "'.implode(',', $media['video_info']['aspect_ratio']).'",
            '.$duration_millis.'
          );';
          $q = mysqli_query($link, $ins);

          foreach ($media['video_info']['variants'] as $variant) {
            $bitrate = (isset($variant['bitrate']))? $variant['bitrate'] : 'NULL';

            $insVariant = 'INSERT INTO TwitterPosts_MediaVariants (
              idMedia,
              bitrate,
              content_type,
              url
            ) VALUES (
            '.$media['id'].',
            '.$bitrate.',
            "'.$variant['content_type'].'",
            "'.$variant['url'].'"
            );';
            $q2 = mysqli_query($link, $insVariant);
            if($q2){
              echo  ($this->debug==1)? NW."Media salva ".$variant['url']."": "";
            }else{
              echo NW.NW."Erro ao salvar Media ".$id.". ".NW."QUERY: ".$insVariant." ".NW."Error:". mysqli_error($link);
            }
          }

          break;

        default:
          // Exception tipo inexistente
          $ins = "INSERT INTO TwitterPostsErrors (id) VALUES (".$id.");";
          mysqli_query($link, $ins);
          echo NW.NW."Erro ao salvar Media ".$id.".";
          break;
      }
      if($q){
        echo  ($this->debug==1)? NW."Media salva ".$media['id']."": "";
      }else{
        echo NW.NW."Erro ao salvar Media ".$id.". ".NW."QUERY: ".$ins." ".NW."Error:". mysqli_error($link);
      }
    }
  }



  private function saveUrls($urls, $id){
    global $link;
    foreach ($urls as $url) {
      /* 
      URL clicada: $urls['url']
      expanded_url url total: $urls['expanded_url']
      display_url url exibida: $urls['display_url']
      indices str que começa q que termina: $urls['indices']
      */
      $ins = 'INSERT INTO TwitterPosts_URL (
        idPost,
        url,
        expanded_url,
        display_url,
        indices
      ) VALUES (
        '.$id.',
        "'.$url['url'].'",
        "'.$url['expanded_url'].'",
        "'.$url['display_url'].'",
        "'.implode(',', $url['indices']).'"
      );';
      $q = mysqli_query($link, $ins);
      if($q){
        echo  ($this->debug==1)? NW."URL salvo ".$url['url']."": "";
      }else{
        echo NW.NW."Erro ao salvar URL ".$id.". ".NW."QUERY: ".$ins." ".NW."Error:". mysqli_error($link);
      }
    }
  }






  private function verifyUser($user){
    // Verifica se o usuário está criado ou se precisa atualizar
    global $link;

    $this->usersValidated[] = $user['id'];
    echo  ($this->debug==1)? NW."VERIFICANDO USER ID: ".$user['id']."": "";

    // Verifica se o user existe ou se precisa atualizar
    $sql = 'SELECT * FROM TwitterUsers WHERE id="'.$user['id'].'"';
    $res = mysqli_query($link, $sql);
    if(mysqli_num_rows($res)==0){
      self::createUser($user);
    }else{
      $row = mysqli_fetch_array($res);
      if($user['name']!=$row['name'] 
      || $user['screen_name']!=$row['screen_name'] 
      || $user['description']!=$row['description'] 
      || $user['url']!=$row['url'] 
      || tof($user['protected'])!=$row['protected'] 
      || tof($user['verified'])!=$row['verified'] 
      || $user['followers_count']!=$row['followers_count'] 
      || $user['friends_count']!=$row['friends_count'] 
      || $user['listed_count']!=$row['listed_count'] 
      || $user['statuses_count']!=$row['statuses_count'] 
      || $user['profile_image_url_https']!=$row['profile_image_url_https'] 
      || $user['profile_banner_url']!=$row['profile_banner_url'] 
      ){
        self::updateUser($user);
      }else{
        echo  ($this->debug==1)? NW."Não Necessário atualizar USER ID: ".$user['id']."": "";
      }
    }
  }







  private function createUser($user){
    // Cria o User
    global $link;

    $ins = 'INSERT INTO TwitterUsers (
      id,
      name,
      screen_name,
      location,
      description,
      url,
      protected,
      verified,
      followers_count,
      friends_count,
      listed_count,
      statuses_count,
      created_at,
      profile_image_url_https,
      profile_banner_url,
      updated_at
    ) VALUES (
      '.$user['id'].',
      "'.addslashes($user['name']).'",
      "'.addslashes($user['screen_name']).'",
      "'.addslashes($user['location']).'",
      "'.addslashes($user['description']).'",
      "'.addslashes($user['url']).'",
      '.tof($user['protected']).',
      '.tof($user['verified']).',
      '.$user['followers_count'].',
      '.$user['friends_count'].',
      '.$user['listed_count'].',
      '.$user['statuses_count'].',
      "'.$user['created_at'].'",
      "'.$user['profile_image_url_https'].'",
      "'.$user['profile_banner_url'].'",
      NOW()
    );';

    $q = mysqli_query($link, $ins);
    if($q){
      echo  ($this->debug==1)? NW."User @".$user['name']." (".$user['id'].") Criado com Sucesso" : "";
    }else{
      echo NW.NW."Erro ao criar User @".$user['name']." (".$user['id']."). ".NW."QUERY: ".$ins." ".NW."Error:". mysqli_error($link );
    }
  }







  private function updateUser($user){
    // Atualiza o User
    global $link; 

    echo  ($this->debug==1)? NW."ATUALIZANDO USER ID: ".$user['id']."": "";
    $upd = 'UPDATE TwitterUsers SET 
      name="'.addslashes($user['name']).'",
      screen_name="'.addslashes($user['screen_name']).'",
      location="'.addslashes($user['location']).'",
      description="'.addslashes($user['description']).'",
      url="'.addslashes($user['url']).'",
      protected='.tof($user['protected']).',
      verified='.tof($user['verified']).',
      followers_count='.$user['followers_count'].',
      friends_count='.$user['friends_count'].',
      listed_count='.$user['listed_count'].',
      statuses_count='.$user['statuses_count'].',
      created_at="'.$user['created_at'].'",
      profile_image_url_https="'.$user['profile_image_url_https'].'",
      profile_banner_url="'.$user['profile_banner_url'].'",
      updated_at=NOW()
    WHERE id = '.$user['id'].';';
    $q = mysqli_query($link, $upd);
    if($q){
      echo  ($this->debug==1)? NW."User @".$user['name']." (".$user['id'].") Atualizado com Sucesso" : "";
    }else{
      echo NW.NW."Erro ao atualizar User @".$user['name']." (".$user['id']."). ".NW."QUERY: ".$upd." ".NW."Error:". mysqli_error($link );
    }
  }




  private function getLastPostID($nickname){
    global $link;
    // VERIFICAR QUAL É O ULTIMO POST QUE TEMOS PARA ASSIM BUSCAR A PARTIR DELE
    // Pega o id do User a partir do TwitterUsers
    $sqlUser = 'SELECT id FROM TwitterUsers WHERE screen_name="'.$nickname.'";';
    $resUser = mysqli_query($link, $sqlUser);
    if(mysqli_num_rows($resUser)>0){
      $rowUser = mysqli_fetch_array($resUser);

      // Pega o ultimo post deste User
      $sql = 'SELECT id FROM TwitterPosts WHERE idUser = '.$rowUser['id'].' ORDER BY created_at_SP DESC LIMIT 1;';
      $res = mysqli_query($link, $sql);
      if(mysqli_num_rows($res)){
        $row = mysqli_fetch_array($res);

        // retorna a string abaixo concatenando o id
        // &since_id="id"
        return "&since_id=".$row['id'];
      }else{
        return "";
      }
    }else{
      return "";
    }
  }

  protected function dateTimeConverter($dateTime){
    // Converte a data do Twitter para datetime GMT -3 
    $date = new DateTime($dateTime);
    $date->setTimezone(new DateTimeZone('America/Sao_Paulo'));
    $formatted_date = $date->format('Y-m-d H:i:s');

    return $formatted_date;
  }

  protected function curl($url) { 
		$headers = array(
      "Authorization: Bearer $this->BEARER_TOKEN"
    );
    $ch = curl_init($url);

    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST => 'GET',
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_RETURNTRANSFER => 1,
    ]);
    
    $result = json_decode(curl_exec($ch), true);
    curl_close($ch);
    return $result;
	}
  
}

$Twitter = new Twitter_API();
$Twitter->getUserPosts('SaoPauloFC');