# Posts/Themen planen
Über dieses Plugin lassen sich Themen und Posts für einen späteren Zeitpunkt vorausplanen.

# Funktionsweise
Beim Erstellen eines neuen Beitrags oder Themas können Mitglieder Posts für einen späteren Zeitpunkt planen. Der Administrator kann einstellen, in welchen Foren diese Funktion aktiv sein soll. Außerdem kann er einstellen, welche Gruppen die Funktion nutzen dürfen. Das Plugin fügt dem Forum einen Task hinzu, das stündlich die geplanten Posts für diesen Zeitpunkt veröffentlicht. Für veröffentlichte Beiträge erhält der Autor einen MyAlert.

# Inkombatibilitäten mit Agreement-Plugin von doylecc
Wer das Plugin "Einverständniserklärung" von doylecc installiert hat, wird auf Inkompabilitätsprobleme stoßen. Die können wiefolgt behoben werden.<br /><br />

Datei inc/plugins/agreement.php<br />
<blockquote>function agreement_guest_post(&$dh)
{
    global $mybb, $lang;

    // Function enabled?
    if (isset($mybb->settings['ag_guest_post']) && $mybb->settings['ag_guest_post'] == 1) {
        // Check if we have a guest
        if ($mybb->user['uid'] == 0 && $dh->method == "insert") {
            $terms_agreed = $mybb->get_input('guestterms', MyBB::INPUT_INT);
            // Box was not checked, display error
            if ($terms_agreed != 1) {
                if (!isset($lang->ag_guestterms_notaccepted)) {
                    $lang->load('agreement');
                }
                $dh->set_error($lang->ag_guestterms_notaccepted);
            }
        }
    }
}</blockquote>

Ersetzen durch
<blockquote>function agreement_guest_post(&$dh)
{
    global $mybb, $lang, $post;

    // Function enabled?
    if (isset($mybb->settings['ag_guest_post']) && $mybb->settings['ag_guest_post'] == 1) {
        // Check if we have a guest
        if ($post['uid'] == 0 && $dh->method == "insert") {
            $terms_agreed = $mybb->get_input('guestterms', MyBB::INPUT_INT);
            // Box was not checked, display error
            if ($terms_agreed != 1) {
                if (!isset($lang->ag_guestterms_notaccepted)) {
                    $lang->load('agreement');
                }
                $dh->set_error($lang->ag_guestterms_notaccepted);
            }
        }
    }
}</blockquote>
