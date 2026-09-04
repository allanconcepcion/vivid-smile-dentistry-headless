<?php
/**
 * Plugin Name:  Vivid Smiles — Editor Guide
 * Description:  Rewrites the wp-admin guidance messages per page, so an editor
 *               can tell which tab, which box and which photo change which part
 *               of which page.
 * Author:       Concepcion.Work
 * Version:      0.1.0
 *
 * WHY THIS FILE EXISTS. The owner opened the home page and the about page in
 * wp-admin and could not see where to edit the images and the content. Both
 * pages were fully editable — the problem was that nothing said so in terms a
 * person could use. A photo lived under a code like drBryceMain with no hint
 * that it is the large portrait of Dr. Richardson, and the one-size orientation
 * message pointed every page at Page sections, which is empty on the handful of
 * template-driven pages where the older tabs are the live controls — and on the
 * home page the Hero tab said “These boxes are live” about boxes the site does
 * not read at all. A control that quietly does nothing is the exact failure the
 * sibling files exist to prevent; a control that works but cannot be found is
 * the same failure seen from the other side.
 *
 * WHAT IT DOES. Five rewrites, all on `acf/prepare_field`, all reading the
 * hardcoded per-page maps below, keyed by the page’s WordPress database ID:
 *
 *   1. The orientation message above the tabs becomes one of three variants —
 *      blocks-composed page (edit words in Page sections, read the Images tab
 *      guide for photos), template page (the exact list of tabs that are live
 *      HERE), or the home page (the headline area is ours, everything below is
 *      yours).
 *   2. The Hero tab message on a page whose hero is not wired (the home page)
 *      is replaced: the top area is managed by us — ask and we connect it.
 *   3. The Images tab opens with a guide naming, for every photo code on THIS
 *      page, what a visitor sees there — and whether the photo is swapped here
 *      or in Page sections these days. The code box on each filled row says to
 *      change the Image, never the code.
 *   4. The Section copy tab likewise opens with a guide mapping each row’s
 *      Section code to the part of the page it feeds, and its status.
 *   5. On pages where the visible questions moved into Page sections, the FAQ
 *      tab says so — and, where the old list still feeds what Google shows for
 *      the page, says to keep the two matching. (The per-page audits called
 *      for this one; it is the same idea as the other four.)
 *
 * WHERE THE MAPS CAME FROM. Read out of the Astro templates in
 * vivid-smiles-website/src/pages/**, cms/import/block-map.json and the live
 * GraphQL endpoint on 2026-09-01, one call site at a time, under one rule: a
 * section()/image() call outside the hasBlocks ternary is live always; one
 * inside the else-branch is dead on every blocks-composed page; a value
 * block-map.json records as moved into a block row is edited in Page sections
 * now, and the guide names the target section by its live heading. Heroes stay
 * template-rendered everywhere, so hero photo rows stay live even on
 * blocks-composed pages.
 *
 * THE GUARD. Every rewrite starts by looking the current page up in the maps.
 * A page these maps do not know — a new page an editor creates, a page
 * recreated under a new ID — is left exactly as it is and keeps today’s static
 * messages, which are written for precisely that general case. Failing back to
 * the old message is the designed behaviour, not an error.
 *
 * WP-ADMIN ONLY. Message and instruction text has no GraphQL surface, so
 * nothing here can change what the build sees. And vs-content-model.php is
 * deliberately untouched: this file hooks the same `acf/prepare_field` filter
 * at priority 20, after that file’s unlock and hide filters have run, and the
 * one field both files touch (the photo code box) is handled on disjoint
 * conditions — that file speaks only to an empty box, this one only to a
 * filled one. Load order between the two does not matter either way: both
 * files only register hooks at load time.
 */

declare( strict_types=1 );

namespace VividSmiles\EditorGuide;

/**
 * The per-page maps.
 *
 * Keys are live WordPress database IDs. `kind` picks the orientation variant:
 * 'home', 'template' (Page sections empty by design, the older tabs are live)
 * or 'blocks' (Page sections owns the page). `liveTabs` is the complete list
 * of tabs that change that page, worded for the orientation message.
 *
 * `images` rows: the photo code, what a visitor sees in that spot, and a
 * status — 'live' (swap it on the Images tab), or 'moved:<heading>' (the photo
 * is changed in Page sections now, in the section with that heading). No page
 * has an unused photo row: even a moved one is still checked by the build, so
 * the guide never invites deleting one.
 *
 * `sections` rows (where present): the same idea for the Section copy tab,
 * with one more status — 'dead', a row the design absorbed: it saves, but
 * nothing on the site reads it any more.
 */
const PAGES = [

	// ── The five template-driven pages: Page sections is empty on purpose ──

	78 => [ // Home
		'route'    => '/',
		'kind'     => 'home',
		'liveTabs' => [ 'Section copy', 'Images' ],
		'images'   => [
			[ 'slot' => 'heroBg', 'where' => 'the full-width photo of the team behind the big headline at the very top — the same photo appears again beside the membership offer lower down', 'status' => 'live' ],
			[ 'slot' => 'logoAACD', 'where' => 'the AACD logo in the scrolling “Accredited by” strip near the top', 'status' => 'live' ],
			[ 'slot' => 'logoAAID', 'where' => 'the AAID logo in the scrolling “Accredited by” strip', 'status' => 'live' ],
			[ 'slot' => 'logoFAM', 'where' => 'the Full Arch Masters logo in the scrolling “Accredited by” strip', 'status' => 'live' ],
			[ 'slot' => 'logoYomi', 'where' => 'the Yomi Robotics logo in the scrolling “Accredited by” strip', 'status' => 'live' ],
			[ 'slot' => 'logoADA', 'where' => 'the ADA logo in the scrolling “Accredited by” strip', 'status' => 'live' ],
			[ 'slot' => 'logoGoogle', 'where' => 'the Google Reviews logo in the scrolling “Accredited by” strip', 'status' => 'live' ],
			[ 'slot' => 'imgVeneers', 'where' => 'the wide photo on the big “Cosmetic & Veneers” card in the services area', 'status' => 'live' ],
			[ 'slot' => 'imgImplants', 'where' => 'the photo on the “Implants” card in the services area', 'status' => 'live' ],
			[ 'slot' => 'imgGeneral', 'where' => 'the photo on the “General Dentistry” card in the services area', 'status' => 'live' ],
			[ 'slot' => 'imgEmergency', 'where' => 'the photo on the “Emergency Care” card in the services area', 'status' => 'live' ],
			[ 'slot' => 'storyVeneersMore', 'where' => 'the photo on the first patient video card (“More than a smile”)', 'status' => 'live' ],
			[ 'slot' => 'storyVeneersConfidence', 'where' => 'the photo on the second patient video card (“Confidence, restored”)', 'status' => 'live' ],
			[ 'slot' => 'storyVeneersLength', 'where' => 'the photo on the third patient video card (“Length & balance”)', 'status' => 'live' ],
			[ 'slot' => 'notableAnnMarie', 'where' => 'the photo of Ann-Marie Muscarello in the Denver Broncos cheerleaders area', 'status' => 'live' ],
			[ 'slot' => 'notableBrittany', 'where' => 'the photo of Brittany Fanning in the Denver Broncos cheerleaders area', 'status' => 'live' ],
			[ 'slot' => 'drPortrait', 'where' => 'the tall portrait of Dr. Richardson beside the Meet the Doctor heading mid-page — also the small round photo above his name at the end of the three-step process', 'status' => 'live' ],
			[ 'slot' => 'techYomi', 'where' => 'the larger photo in the technology area — Dr. Richardson with the robotic arm', 'status' => 'live' ],
			[ 'slot' => 'techVeneerShells', 'where' => 'the smaller overlapping photo in the technology area — veneer shells on a model', 'status' => 'live' ],
		],
		'imagesNote' => 'Not on this list: the scrolling smile photos in the dark area lower down — add or remove those under Practice Settings → Smile gallery in the left menu.',
		'sections' => [
			[ 'id' => 'services', 'where' => 'the small line, heading and paragraph above the four service cards near the top', 'status' => 'live' ],
			[ 'id' => 'stories', 'where' => 'the small line, heading and paragraph above the three patient video cards', 'status' => 'live' ],
			[ 'id' => 'notable', 'where' => 'the small line, heading and paragraph above the two Denver Broncos cheerleader photos', 'status' => 'live' ],
			[ 'id' => 'doctor', 'where' => 'the small line, heading and lead paragraph of the Meet the Doctor area mid-page', 'status' => 'live' ],
			[ 'id' => 'gallery', 'where' => 'the heading and paragraph of the dark area with the scrolling smile photos', 'status' => 'live' ],
			[ 'id' => 'technology', 'where' => 'the small line, heading and lead paragraph of the technology area with the robot photo', 'status' => 'live' ],
		],
	],

	79 => [ // About Us
		'route'    => '/about-us/',
		'kind'     => 'template',
		'liveTabs' => [ 'Hero', 'Section copy', 'Images', 'Bottom of page (the consultation invite)' ],
		'images'   => [
			[ 'slot' => 'heroTeam', 'where' => 'the photo beside the headline at the very top — Dr. Annie and Dr. Bryce together', 'status' => 'live' ],
			[ 'slot' => 'storyOffice', 'where' => 'the photo in the Our Story area — the neon smile sign inside the office', 'status' => 'live' ],
			[ 'slot' => 'drBryceMain', 'where' => 'the large portrait of Dr. Richardson in the Meet the Doctors area', 'status' => 'live' ],
			[ 'slot' => 'drBryceInset', 'where' => 'the small photo overlapping Dr. Richardson’s portrait — him working in surgical loupes', 'status' => 'live' ],
			[ 'slot' => 'drAnnieMain', 'where' => 'the large portrait of Dr. Annie in the Meet the Doctors area', 'status' => 'live' ],
			[ 'slot' => 'drAnnieInset', 'where' => 'the small photo overlapping Dr. Annie’s portrait', 'status' => 'live' ],
			[ 'slot' => 'teamSara', 'where' => 'Sara’s photo (Office Manager) in the team area', 'status' => 'live' ],
			[ 'slot' => 'teamKt', 'where' => 'KT’s photo (Patient Care & Office Coordinator) in the team area', 'status' => 'live' ],
			[ 'slot' => 'teamCarol', 'where' => 'Carol’s photo (Patient Coordinator) in the team area', 'status' => 'live' ],
			[ 'slot' => 'teamMandy', 'where' => 'Mandy’s photo (Dental Hygienist) in the team area', 'status' => 'live' ],
			[ 'slot' => 'teamSammie', 'where' => 'Sammie’s photo (Dental Hygienist) in the team area', 'status' => 'live' ],
			[ 'slot' => 'teamLinh', 'where' => 'Linh’s photo (Dental Assistant) in the team area', 'status' => 'live' ],
			[ 'slot' => 'teamTina', 'where' => 'Tina’s photo (Dental Assistant) in the team area', 'status' => 'live' ],
			[ 'slot' => 'teamKnox', 'where' => 'Knox’s photo (the dog, Director of Smiles) in the team area', 'status' => 'live' ],
			[ 'slot' => 'teamAlly', 'where' => 'nothing — this code is not used anywhere on the site', 'status' => 'unused' ],
			[ 'slot' => 'teamBirdie', 'where' => 'Birdie’s photo (the dog, Chief Comfort Officer) in the team area', 'status' => 'live' ],
			[ 'slot' => 'yomiImg', 'where' => 'the photo in the technology area — a treatment room', 'status' => 'live' ],
			[ 'slot' => 'aacdLogo', 'where' => 'the AACD logo card in the credentials area near the bottom', 'status' => 'live' ],
			[ 'slot' => 'aaidLogo', 'where' => 'the AAID logo card in the credentials area', 'status' => 'live' ],
			[ 'slot' => 'famLogo', 'where' => 'the Full Arch Masters logo card in the credentials area', 'status' => 'live' ],
			[ 'slot' => 'iuLogo', 'where' => 'the Indiana University logo card in the credentials area', 'status' => 'live' ],
			[ 'slot' => 'yomiLogo', 'where' => 'the Yomi Robotics logo card in the credentials area', 'status' => 'live' ],
			[ 'slot' => 'adaLogo', 'where' => 'the ADA logo card in the credentials area', 'status' => 'live' ],
			[ 'slot' => 'googleLogo', 'where' => 'the Google Reviews logo card in the credentials area', 'status' => 'live' ],
			[ 'slot' => 'vsLogo', 'where' => 'the Vivid Smiles logo card in the credentials area', 'status' => 'live' ],
		],
		'sections' => [
			[ 'id' => 'story', 'where' => 'the heading and first paragraph beside the neon-sign photo in Our Story', 'status' => 'live' ],
			[ 'id' => 'doctors', 'where' => 'Dr. Richardson’s name heading and his short intro line in Meet the Doctors (Dr. Annie’s wording is managed by us)', 'status' => 'live' ],
			[ 'id' => 'technology', 'where' => 'the heading and lead paragraph beside the treatment-room photo in the technology area', 'status' => 'live' ],
			[ 'id' => 'voices', 'where' => 'the heading and paragraph above the scrolling patient reviews', 'status' => 'live' ],
		],
	],

	90 => [ // Patient Testimonials
		'route'    => '/patient-testimonials/',
		'kind'     => 'template',
		'liveTabs' => [ 'Hero', 'Section copy', 'Images', 'Bottom of page (the consultation invite)' ],
		'images'   => [
			[ 'slot' => 'heroImg', 'where' => 'the photo beside the headline at the very top — the team walking outside the office', 'status' => 'live' ],
			[ 'slot' => 'veneerMore', 'where' => 'the tall photo on the first veneer video card (“More than a smile”)', 'status' => 'live' ],
			[ 'slot' => 'veneerConfidence', 'where' => 'the tall photo on the “Confidence, restored” veneer video card', 'status' => 'live' ],
			[ 'slot' => 'veneerLength', 'where' => 'the tall photo on the “Length & balance” veneer video card', 'status' => 'live' ],
			[ 'slot' => 'veneerFromTo', 'where' => 'the tall photo on the “From veneers to All-on-X” video card', 'status' => 'live' ],
			[ 'slot' => 'veneerBonding', 'where' => 'the tall photo on the “Bonding or veneers?” video card', 'status' => 'live' ],
			[ 'slot' => 'veneerAnneMarie', 'where' => 'the tall photo on Anne Marie’s video card', 'status' => 'live' ],
			[ 'slot' => 'veneerPrep', 'where' => 'the tall photo on the “No-prep or minimal-prep?” video card', 'status' => 'live' ],
			[ 'slot' => 'storyJames', 'where' => 'the photo on James’s video card in the patient stories area', 'status' => 'live' ],
			[ 'slot' => 'storyChanel', 'where' => 'the photo on Chanel’s video card in the patient stories area', 'status' => 'live' ],
			[ 'slot' => 'storyJosh', 'where' => 'the photo on Josh’s video card in the patient stories area', 'status' => 'live' ],
			[ 'slot' => 'featuredImg', 'where' => 'the large photo on Wayne’s featured story — a full-arch restoration held in a gloved hand', 'status' => 'live' ],
		],
		'sections' => [
			[ 'id' => 'veneer-stories', 'where' => 'the small line, heading and paragraph above the seven tall veneer video cards', 'status' => 'live' ],
			[ 'id' => 'stories', 'where' => 'the small line, heading and paragraph above the James / Chanel / Josh video cards', 'status' => 'live' ],
			[ 'id' => 'featured-story', 'where' => 'the small line, heading and the quote paragraph beside Wayne’s featured video', 'status' => 'live' ],
			[ 'id' => 'reviews', 'where' => 'the small line, heading and paragraph above the scrolling Google reviews', 'status' => 'live' ],
		],
	],

	94 => [ // Smile Gallery
		'route'    => '/smile-gallery/',
		'kind'     => 'template',
		'liveTabs' => [ 'Hero', 'Section copy', 'Images', 'Bottom of page (the consultation invite)' ],
		'images'   => [
			[ 'slot' => 'heroImg', 'where' => 'the photo beside the headline at the very top — a close-up of a patient smiling', 'status' => 'live' ],
		],
		'imagesNote' => 'Not on this list: the grid of smile photos itself — add, remove and reorder those under Practice Settings → Smile gallery in the left menu.',
		'sections' => [
			[ 'id' => 'gallery', 'where' => 'the small line, heading and paragraph above the grid of smile photos', 'status' => 'live' ],
		],
	],

	83 => [ // Dental Membership Plan
		'route'    => '/dental-membership-plan/',
		'kind'     => 'template',
		'liveTabs' => [ 'Hero', 'Section copy', 'Images', 'Bottom of page (the booking-strip sentence only)' ],
		'images'   => [
			[ 'slot' => 'heroImg', 'where' => 'the photo beside the headline at the very top — the whole team', 'status' => 'live' ],
			[ 'slot' => 'step1Img', 'where' => 'the small square photo on step one (“Enroll”) in the How it works card', 'status' => 'live' ],
			[ 'slot' => 'step2Img', 'where' => 'the small square photo on step two (“Come in”) in the How it works card', 'status' => 'live' ],
			[ 'slot' => 'step3Img', 'where' => 'the small square photo on step three (“Renew”) in the How it works card', 'status' => 'live' ],
		],
		'sections' => [
			[ 'id' => 'story', 'where' => 'the heading and first paragraph of the dark “Why we built it” area', 'status' => 'live' ],
			[ 'id' => 'how-it-works', 'where' => 'the heading and paragraph above the three-step card', 'status' => 'live' ],
			[ 'id' => 'whats-included', 'where' => 'the centered heading and paragraph above the big $500 plan card', 'status' => 'live' ],
			[ 'id' => 'faq', 'where' => 'the heading above the questions-and-answers list (the questions themselves are managed by us)', 'status' => 'live' ],
		],
	],

	// ── Cosmetic dentistry: seven blocks-composed pages ──

	82 => [ // Cosmetic Dentistry hub
		'route'    => '/cosmetic-dentistry/',
		'kind'     => 'blocks',
		'liveTabs' => [ 'Hero', 'Images (the top photo)', 'Page sections', 'Bottom of page' ],
		'faq'      => 'mirror',
		'images'   => [
			[ 'slot' => 'imgHero', 'where' => 'the big photo to the right of the headline at the very top of the page — currently a smiling woman at the neon smile wall', 'status' => 'live' ],
			[ 'slot' => 'imgVeneers', 'where' => 'the photo on the Porcelain Veneers card in the services grid mid-page', 'status' => 'moved:Cosmetic dentistry services at Vivid Smiles.' ],
			[ 'slot' => 'imgAligners', 'where' => 'the photo on the Clear Aligners card in the services grid mid-page', 'status' => 'moved:Cosmetic dentistry services at Vivid Smiles.' ],
			[ 'slot' => 'imgWhitening', 'where' => 'the photo on the Teeth Whitening card in the services grid mid-page', 'status' => 'moved:Cosmetic dentistry services at Vivid Smiles.' ],
			[ 'slot' => 'imgGum', 'where' => 'the photo on the Gum Contouring card in the services grid mid-page', 'status' => 'moved:Cosmetic dentistry services at Vivid Smiles.' ],
			[ 'slot' => 'imgMakeover', 'where' => 'the photo on the Smile Makeover card in the services grid mid-page', 'status' => 'moved:Cosmetic dentistry services at Vivid Smiles.' ],
			[ 'slot' => 'imgFullMouth', 'where' => 'the photo on the Full Mouth Rehabilitation card in the services grid mid-page', 'status' => 'moved:Cosmetic dentistry services at Vivid Smiles.' ],
			[ 'slot' => 'imgDsd', 'where' => 'the smile close-up next to “Preview your results before you commit” in the dark technology section', 'status' => 'moved:Better outcomes, fewer appointments.' ],
			[ 'slot' => 'imgDrBryce', 'where' => 'the large portrait of Dr. Bryce Richardson in the meet-the-doctor section', 'status' => 'moved:Meet Dr. Bryce Richardson — education, training & expertise.' ],
			[ 'slot' => 'imgDrAnnie', 'where' => 'the small round headshot of Dr. Annie next to “Also at the practice” in the meet-the-doctor section', 'status' => 'moved:Meet Dr. Bryce Richardson — education, training & expertise.' ],
		],
	],

	98 => [ // Clear Aligners
		'route'    => '/cosmetic-dentistry/clear-aligners/',
		'kind'     => 'blocks',
		'liveTabs' => [ 'Hero', 'Images (the top photo)', 'Page sections', 'Bottom of page' ],
		'faq'      => 'mirror',
		'images'   => [
			[ 'slot' => 'heroImg', 'where' => 'the big photo next to the headline at the very top of the page — currently a patient holding a clear aligner tray', 'status' => 'live' ],
			[ 'slot' => 'whatImg', 'where' => 'the photo beside the “What Are Clear Aligners?” heading — currently a smiling woman by a mountain lake', 'status' => 'moved:What Are Clear Aligners?' ],
			[ 'slot' => 'naturalImg', 'where' => 'the photo in the “Who Is a Good Candidate for Clear Aligners?” section — currently a smiling woman photographed indoors', 'status' => 'moved:Who Is a Good Candidate for Clear Aligners?' ],
		],
	],

	99 => [ // Full Mouth Rehabilitation
		'route'    => '/cosmetic-dentistry/full-mouth-rehabilitation/',
		'kind'     => 'blocks',
		'liveTabs' => [ 'Hero', 'Images (the top photo)', 'Page sections', 'Bottom of page' ],
		'faq'      => 'mirror',
		'images'   => [
			[ 'slot' => 'heroImg', 'where' => 'the big photo next to the headline at the very top of the page — currently a smiling woman at the office', 'status' => 'live' ],
			[ 'slot' => 'whatImg', 'where' => 'the photo beside the “What is Full-Mouth Rehabilitation?” heading — currently a full-arch restoration held on a dental model', 'status' => 'moved:What is Full-Mouth Rehabilitation?' ],
			[ 'slot' => 'designImg', 'where' => 'the photo in the “Digital Smile Design and Treatment Planning” section — currently a smile in profile against black', 'status' => 'moved:Digital Smile Design and Treatment Planning' ],
		],
	],

	100 => [ // Gum Contouring
		'route'    => '/cosmetic-dentistry/gum-contouring/',
		'kind'     => 'blocks',
		'liveTabs' => [ 'Hero', 'Images (the top photo)', 'Page sections', 'Bottom of page' ],
		'faq'      => 'mirror',
		'images'   => [
			[ 'slot' => 'heroImg', 'where' => 'the big photo next to the headline at the very top of the page — currently a smiling woman outdoors among Colorado pines', 'status' => 'live' ],
			[ 'slot' => 'whatImg', 'where' => 'the photo beside the “What Is Gum Contouring?” heading — currently a close-up of a smile showing the gum line', 'status' => 'moved:What Is Gum Contouring?' ],
			[ 'slot' => 'gumlineImg', 'where' => 'the photo in the laser-technology section — currently a side-lit close-up of lips and upper teeth', 'status' => 'moved:How Laser Technology Makes Gum Contouring Gentle and Precise' ],
			[ 'slot' => 'resultsImg', 'where' => 'the photo in the “Gum Contouring Results: What to Expect” section — currently a smiling woman inside the office', 'status' => 'moved:Gum Contouring Results: What to Expect' ],
		],
	],

	101 => [ // Porcelain Veneers
		'route'    => '/cosmetic-dentistry/porcelain-veneers/',
		'kind'     => 'blocks',
		'liveTabs' => [ 'Hero', 'Images (the top photo)', 'Page sections', 'Bottom of page' ],
		'faq'      => 'mirror',
		'images'   => [
			[ 'slot' => 'heroImg', 'where' => 'the big photo next to the headline at the very top of the page — currently a patient in front of the practice neon sign', 'status' => 'live' ],
			[ 'slot' => 'whatImg', 'where' => 'the photo beside the “What Are Porcelain Veneers?” heading — currently veneer shells seated on a dental model', 'status' => 'moved:What Are Porcelain Veneers?' ],
			[ 'slot' => 'naturalImg', 'where' => 'the photo in the “Achieving Natural-Looking Results” section — currently a close-up of finished porcelain veneers', 'status' => 'moved:Achieving Natural-Looking Results' ],
		],
	],

	102 => [ // Smile Makeover
		'route'    => '/cosmetic-dentistry/smile-makeover/',
		'kind'     => 'blocks',
		'liveTabs' => [ 'Hero', 'Images (the top photo)', 'Page sections', 'Bottom of page' ],
		'faq'      => 'mirror',
		'images'   => [
			[ 'slot' => 'heroImg', 'where' => 'the big photo next to the headline at the very top of the page — currently a smiling woman against a dark studio background', 'status' => 'live' ],
			[ 'slot' => 'whatImg', 'where' => 'the photo beside the “What Is a Smile Makeover?” heading — currently a smile at a three-quarter angle against black', 'status' => 'moved:What Is a Smile Makeover?' ],
			[ 'slot' => 'editorialImg', 'where' => 'the photo in the makeover-process section — currently an editorial smile close-up with berry lipstick', 'status' => 'moved:Our Smile Makeover Process: Digital Design to Final Result' ],
		],
	],

	103 => [ // Teeth Whitening
		'route'           => '/cosmetic-dentistry/teeth-whitening/',
		'kind'            => 'blocks',
		'liveTabs'        => [ 'Hero', 'Images (the top photo)', 'Page sections', 'Bottom of page' ],
		'faq'             => 'mirror',
		// The heading is the live Page sections row, read from the endpoint on
		// 2026-09-01 — the four whitening prices are a price list of their own.
		'orientationNote' => 'The four whitening prices are edited in Page sections too — open the row headed “Teeth Whitening Pricing in Parker, Colorado”.',
		'images'          => [
			[ 'slot' => 'heroImg', 'where' => 'the big photo next to the headline at the very top of the page — currently a smiling man at the neon smile wall', 'status' => 'live' ],
			[ 'slot' => 'whatImg', 'where' => 'the photo beside the “What Is KöR Teeth Whitening?” heading — currently a close-up of a natural smile in daylight', 'status' => 'moved:What Is KöR Teeth Whitening?' ],
			[ 'slot' => 'naturalImg', 'where' => 'the photo in the “Who Is a Good Candidate for Professional Teeth Whitening?” section — currently a smiling woman photographed indoors', 'status' => 'moved:Who Is a Good Candidate for Professional Teeth Whitening?' ],
		],
	],

	// ── Implant dentistry: six blocks-composed pages ──

	87 => [ // Implant Dentistry hub
		'route'    => '/implant-dentistry/',
		'kind'     => 'blocks',
		'liveTabs' => [ 'Hero', 'Images (the top photo)', 'Page sections', 'Bottom of page' ],
		'faq'      => 'mirror',
		'images'   => [
			[ 'slot' => 'imgHero', 'where' => 'the large photo beside the headline at the very top of the page — an implant-supported restoration held on a dental model', 'status' => 'live' ],
			[ 'slot' => 'imgWhat', 'where' => 'the photo on the left of the “What are dental implants?” section — a smiling woman at the Vivid Smiles office', 'status' => 'moved:What are dental implants?' ],
			[ 'slot' => 'imgSingleTooth', 'where' => 'the photo on the first of the five clickable service tiles (“Single Tooth Implants”), below the pricing table', 'status' => 'moved:Service tiles' ],
			[ 'slot' => 'imgFullMouth', 'where' => 'the photo on the “Full Mouth Implants” service tile — a smiling woman beside a mountain lake', 'status' => 'moved:Service tiles' ],
			[ 'slot' => 'imgAllOn4', 'where' => 'the photo on the “All-on-4 Single Arch” service tile — a smiling man at the neon smile wall', 'status' => 'moved:Service tiles' ],
			[ 'slot' => 'imgBoneGrafting', 'where' => 'the photo on the “Bone Grafting” service tile — a smiling woman inside the office', 'status' => 'moved:Service tiles' ],
			[ 'slot' => 'imgSinusLift', 'where' => 'the photo on the “Sinus Lift” service tile — a treatment room at the practice', 'status' => 'moved:Service tiles' ],
			[ 'slot' => 'imgDrBryce', 'where' => 'the portrait of Dr. Bryce Richardson in the meet-the-doctor area mid-page', 'status' => 'moved:Dr. Richardson’s implant training and experience.' ],
		],
	],

	104 => [ // All-on-4 Single Arch
		'route'    => '/implant-dentistry/all-on-4-single-arch/',
		'kind'     => 'blocks',
		'liveTabs' => [ 'Hero', 'Images (the top photo)', 'Page sections', 'Bottom of page' ],
		'faq'      => 'mirror',
		'images'   => [
			[ 'slot' => 'heroImg', 'where' => 'the large photo beside the headline at the very top of the page — a smiling woman at the Vivid Smiles office', 'status' => 'live' ],
			[ 'slot' => 'whatImg', 'where' => 'the photo on the left of the “What Are All-on-4 Single Arch Implants?” section — a full set of teeth on a dental model, held in a gloved hand', 'status' => 'moved:What Are All-on-4 Single Arch Implants?' ],
			[ 'slot' => 'roboticsImg', 'where' => 'the photo of Dr. Richardson using the robotic arm, in the section about robotic placement', 'status' => 'moved:How Robotic All-on-4 Placement Works at Vivid Smiles' ],
			[ 'slot' => 'candidacyImg', 'where' => 'the photo on the right of the who-is-a-candidate section — a smiling woman outdoors in the foothills', 'status' => 'moved:Who Is a Good Candidate for Single Arch All-on-4?' ],
		],
	],

	105 => [ // Bone Grafting
		'route'    => '/implant-dentistry/bone-grafting/',
		'kind'     => 'blocks',
		'liveTabs' => [ 'Hero', 'Images (the top photo)', 'Page sections', 'Bottom of page' ],
		'faq'      => 'mirror',
		'images'   => [
			[ 'slot' => 'heroImg', 'where' => 'the large photo beside the headline at the very top of the page — a smiling man at the neon smile wall', 'status' => 'live' ],
			[ 'slot' => 'whatImg', 'where' => 'the illustration on the left of the “What Is Bone Grafting?” section — a cross-section showing graft material under a membrane', 'status' => 'moved:What Is Bone Grafting?' ],
			[ 'slot' => 'implantsImg', 'where' => 'the smaller framed photo in the grafting-and-implants area mid-page — an implant restoration on a dental model', 'status' => 'moved:Bone Grafting and Dental Implants' ],
			[ 'slot' => 'candidacyImg', 'where' => 'the photo on the right of the who-needs-it section — a woman beside a stone wall on a bright afternoon', 'status' => 'moved:Who Needs Bone Grafting?' ],
		],
	],

	106 => [ // Full Mouth Dental Implants
		'route'    => '/implant-dentistry/full-mouth-dental-implants/',
		'kind'     => 'blocks',
		'liveTabs' => [ 'Hero', 'Images (the top photo)', 'Page sections', 'Bottom of page' ],
		'faq'      => 'mirror',
		'images'   => [
			[ 'slot' => 'heroImg', 'where' => 'the large photo beside the headline at the very top of the page — a smiling woman inside the Vivid Smiles office', 'status' => 'live' ],
			[ 'slot' => 'whatImg', 'where' => 'the photo on the left of the “What Are Full Mouth Dental Implants?” section — a full set of teeth on a dental model, held in a gloved hand', 'status' => 'moved:What Are Full Mouth Dental Implants?' ],
			[ 'slot' => 'roboticsImg', 'where' => 'the photo of Dr. Richardson using the robotic arm, in the section about robotic placement', 'status' => 'moved:Robotic Implant Placement: Precision Technology' ],
			[ 'slot' => 'candidacyImg', 'where' => 'the photo on the right of the who-is-a-candidate section — a woman seated in tall grass above a Colorado lake', 'status' => 'moved:Who Is a Candidate for Full Mouth Implants?' ],
		],
	],

	107 => [ // Single Tooth Dental Implants
		'route'    => '/implant-dentistry/single-tooth-dental-implants/',
		'kind'     => 'blocks',
		'liveTabs' => [ 'Hero', 'Images (the top photo)', 'Page sections', 'Bottom of page' ],
		'faq'      => 'mirror',
		'images'   => [
			[ 'slot' => 'heroImg', 'where' => 'the large photo beside the headline at the very top of the page — a smiling woman photographed indoors', 'status' => 'live' ],
			[ 'slot' => 'whatImg', 'where' => 'the photo on the left of the “Why Choose a Single Tooth Implant?” section — a close-up of an implant restoration on a dental model', 'status' => 'moved:Why Choose a Single Tooth Implant?' ],
			[ 'slot' => 'candidacyImg', 'where' => 'the photo on the right of the who-is-a-candidate section — a woman among pines on a Colorado hillside', 'status' => 'moved:Who Is a Candidate for Single Tooth Implants?' ],
		],
	],

	108 => [ // Sinus Lift
		'route'    => '/implant-dentistry/sinus-lift/',
		'kind'     => 'blocks',
		'liveTabs' => [ 'Hero', 'Images (the top photo)', 'Page sections', 'Bottom of page' ],
		'faq'      => 'mirror',
		'images'   => [
			[ 'slot' => 'heroImg', 'where' => 'the large photo beside the headline at the very top of the page — a smiling woman outdoors in the foothills', 'status' => 'live' ],
			[ 'slot' => 'drRichardsonImg', 'where' => 'the portrait of Dr. Bryce Richardson in the first section under the headline area (why choose Vivid Smiles)', 'status' => 'moved:Why Choose Vivid Smiles for Your Sinus Lift in Parker, CO' ],
			[ 'slot' => 'whatImg', 'where' => 'the illustration on the left of the “What Is a Sinus Lift?” section — a cross-section of graft material beneath the lifted sinus membrane', 'status' => 'moved:What Is a Sinus Lift?' ],
			[ 'slot' => 'yomiImg', 'where' => 'the photo of Dr. Richardson using the robotic arm, in the technology section lower on the page', 'status' => 'moved:Advanced Technology for Predictable Outcomes' ],
		],
	],

	// ── The rest: seven blocks-composed pages with mixed older tabs ──

	85 => [ // General Dentistry
		'route'    => '/general-dentistry/',
		'kind'     => 'blocks',
		'liveTabs' => [ 'Hero', 'Images (the top photo)', 'Page sections', 'Bottom of page' ],
		'faq'      => 'mirror',
		'images'   => [
			[ 'slot' => 'imgHero', 'where' => 'the big photo to the right of the headline at the very top of the page — a hygienist polishing a patient’s teeth', 'status' => 'live' ],
			[ 'slot' => 'imgPreventive', 'where' => 'the photo on the “Preventive Care & Cleanings” card in the four-card services row', 'status' => 'moved:General dentistry services at Vivid Smiles.' ],
			[ 'slot' => 'imgRestorative', 'where' => 'the photo on the “Fillings, Crowns & Bridges” card in that same row', 'status' => 'moved:General dentistry services at Vivid Smiles.' ],
			[ 'slot' => 'imgPeriodontal', 'where' => 'the photo on the “Periodontal Care” card in that same row', 'status' => 'moved:General dentistry services at Vivid Smiles.' ],
			[ 'slot' => 'imgEmergency', 'where' => 'the photo on the “Emergency Dental Care” card in that same row', 'status' => 'moved:General dentistry services at Vivid Smiles.' ],
			[ 'slot' => 'imgFirstVisit', 'where' => 'the photo of the practice’s etched entry doors beside the first-visit copy', 'status' => 'moved:What to expect during your first general dentistry visit.' ],
			[ 'slot' => 'imgGateway', 'where' => 'the portrait at the neon smile wall beside the cosmetic-care copy', 'status' => 'moved:General dentistry as a gateway to cosmetic care.' ],
			[ 'slot' => 'imgDoctors', 'where' => 'the photo of both doctors in the meet-the-doctors area', 'status' => 'moved:Meet Dr. Richardson and Dr. Annie.' ],
		],
		'sections' => [
			[ 'id' => 'why', 'where' => 'the dark technology-and-stats area under the headline', 'status' => 'moved:Technology-forward, family-focused care.' ],
			[ 'id' => 'services', 'where' => 'the intro above the four service cards', 'status' => 'moved:General dentistry services at Vivid Smiles.' ],
			[ 'id' => 'first-visit', 'where' => 'the first-visit area with the entry-doors photo', 'status' => 'moved:What to expect during your first general dentistry visit.' ],
			[ 'id' => 'gateway', 'where' => 'the cosmetic-care area with the neon-wall portrait', 'status' => 'moved:General dentistry as a gateway to cosmetic care.' ],
			[ 'id' => 'trust', 'where' => 'the dark “Why Parker trusts us” quote area mid-page', 'status' => 'dead' ],
			[ 'id' => 'doctors', 'where' => 'the meet-the-doctors area', 'status' => 'moved:Meet Dr. Richardson and Dr. Annie.' ],
			[ 'id' => 'payment', 'where' => 'the insurance-and-membership area', 'status' => 'dead' ],
			[ 'id' => 'area', 'where' => 'the map-and-address area', 'status' => 'dead' ],
			[ 'id' => 'faq', 'where' => 'the intro above the questions-and-answers list', 'status' => 'moved:Frequently asked questions about general dentistry in Parker, CO.' ],
		],
	],

	84 => [ // Emergency Dental Care
		'route'    => '/emergency-dentistry/',
		'kind'     => 'blocks',
		'liveTabs' => [ 'Hero', 'Images (the top photo)', 'Page sections', 'Section copy (only the message-form heading row)', 'Bottom of page (the booking-strip sentence only)' ],
		'faq'      => 'inert',
		'images'   => [
			[ 'slot' => 'imgHero', 'where' => 'the big photo to the right of the headline at the very top of the page — a smiling woman in a warm interior', 'status' => 'live' ],
			[ 'slot' => 'imgHow', 'where' => 'the photo of a dentist and assistant treating a patient, beside the how-we-handle-emergencies copy', 'status' => 'moved:How Vivid Smiles handles emergency dental cases.' ],
		],
		'sections' => [
			[ 'id' => 'when', 'where' => 'the dark area under the headline with the three number cards', 'status' => 'moved:When to seek emergency dental care.' ],
			[ 'id' => 'protocol', 'where' => 'the “In case of” scenario cards', 'status' => 'dead' ],
			[ 'id' => 'how', 'where' => 'the area with the treatment photo mid-page', 'status' => 'moved:How Vivid Smiles handles emergency dental cases.' ],
			[ 'id' => 'emergencies', 'where' => 'the checklist of common emergencies', 'status' => 'moved:Common dental emergencies.' ],
			[ 'id' => 'why-us', 'where' => 'the dark “Why choose Vivid Smiles” quote area', 'status' => 'dead' ],
			[ 'id' => 'area', 'where' => 'the map-and-address area', 'status' => 'dead' ],
			[ 'id' => 'prevent', 'where' => 'the preventing-emergencies area near the bottom', 'status' => 'dead' ],
			[ 'id' => 'faq', 'where' => 'the intro above the questions-and-answers list', 'status' => 'moved:Dental emergency FAQs.' ],
			[ 'id' => 'contact-form', 'where' => 'the heading beside the message form at the very bottom of the page (only the Heading box changes the page)', 'status' => 'live' ],
		],
	],

	93 => [ // Services
		'route'    => '/services/',
		'kind'     => 'blocks',
		'liveTabs' => [
			'Page sections (the three photo grids and their intros)',
			'Section copy (only the “process” and “faq” rows)',
			'FAQ (this page’s questions and answers are edited on that tab, not in Page sections)',
			'Bottom of page (the consultation invite)',
			'Hero (the headline box only — invisible on the page, it speaks to search engines and screen readers)',
		],
		'images'   => [
			[ 'slot' => 'imgVeneers', 'where' => 'the photo on the “Porcelain Veneers” card in the cosmetic grid', 'status' => 'moved:Where artistry meets esthetic dentistry.' ],
			[ 'slot' => 'imgSmileMakeover', 'where' => 'the photo on the “Smile Makeover” card in the cosmetic grid', 'status' => 'moved:Where artistry meets esthetic dentistry.' ],
			[ 'slot' => 'imgClearAligners', 'where' => 'the photo on the “Clear Aligners” card in the cosmetic grid', 'status' => 'moved:Where artistry meets esthetic dentistry.' ],
			[ 'slot' => 'imgWhitening', 'where' => 'the photo on the “Teeth Whitening” card in the cosmetic grid', 'status' => 'moved:Where artistry meets esthetic dentistry.' ],
			[ 'slot' => 'imgGumContouring', 'where' => 'the photo on the “Gum Contouring” card in the cosmetic grid', 'status' => 'moved:Where artistry meets esthetic dentistry.' ],
			[ 'slot' => 'imgFullMouthRehab', 'where' => 'the photo on the “Full Mouth Rehabilitation” card in the cosmetic grid', 'status' => 'moved:Where artistry meets esthetic dentistry.' ],
			[ 'slot' => 'imgSingleImplant', 'where' => 'the photo on the “Single Tooth Implant” card in the implant grid', 'status' => 'moved:Replace what’s missing with sub-millimeter accuracy.' ],
			[ 'slot' => 'imgFullMouthImplants', 'where' => 'the photo on the “Full Mouth Implants” card in the implant grid', 'status' => 'moved:Replace what’s missing with sub-millimeter accuracy.' ],
			[ 'slot' => 'imgAllOn4', 'where' => 'the photo on the “All-on-4 Single Arch” card in the implant grid', 'status' => 'moved:Replace what’s missing with sub-millimeter accuracy.' ],
			[ 'slot' => 'imgBoneGraft', 'where' => 'the photo on the “Bone Grafting” card in the implant grid', 'status' => 'moved:Replace what’s missing with sub-millimeter accuracy.' ],
			[ 'slot' => 'imgSinusLift', 'where' => 'the photo on the “Sinus Lift” card in the implant grid', 'status' => 'moved:Replace what’s missing with sub-millimeter accuracy.' ],
			[ 'slot' => 'imgGeneral', 'where' => 'the photo on the “General Dentistry” card in the everyday-care pair', 'status' => 'moved:When life happens, we’re here.' ],
			[ 'slot' => 'imgEmergency', 'where' => 'the photo on the “Emergency Dentistry” card in the everyday-care pair', 'status' => 'moved:When life happens, we’re here.' ],
		],
		'sections' => [
			[ 'id' => 'cosmetic', 'where' => 'the intro above the cosmetic services grid at the top', 'status' => 'moved:Where artistry meets esthetic dentistry.' ],
			[ 'id' => 'implants', 'where' => 'the intro above the implant services grid', 'status' => 'moved:Replace what’s missing with sub-millimeter accuracy.' ],
			[ 'id' => 'everyday', 'where' => 'the intro above the everyday-care pair', 'status' => 'moved:When life happens, we’re here.' ],
			[ 'id' => 'process', 'where' => 'the intro above the three how-we-work steps (the steps themselves are part of the design)', 'status' => 'live' ],
			[ 'id' => 'faq', 'where' => 'the intro above the questions-and-answers list', 'status' => 'live' ],
		],
	],

	88 => [ // New Patients
		'route'    => '/new-patients/',
		'kind'     => 'blocks',
		'liveTabs' => [ 'Hero', 'Images (the top photo)', 'Page sections', 'Section copy (only the “membership” row)', 'Bottom of page (the consultation invite)' ],
		'faq'      => 'inert',
		'images'   => [
			[ 'slot' => 'heroImg', 'where' => 'the big photo to the right of the headline at the very top — the team walking outside the office', 'status' => 'live' ],
			[ 'slot' => 'imgCosmetic', 'where' => 'the photo on the “Cosmetic Dentistry” card in the four-card services row', 'status' => 'moved:Care that fits every chapter of your smile.' ],
			[ 'slot' => 'imgImplant', 'where' => 'the photo on the “Implant Dentistry” card in that same row', 'status' => 'moved:Care that fits every chapter of your smile.' ],
			[ 'slot' => 'imgGeneral', 'where' => 'the photo on the “General Dentistry” card in that same row', 'status' => 'moved:Care that fits every chapter of your smile.' ],
			[ 'slot' => 'imgEmergency', 'where' => 'the photo on the “Emergency Dentistry” card in that same row', 'status' => 'moved:Care that fits every chapter of your smile.' ],
		],
		'sections' => [
			[ 'id' => 'first-visit', 'where' => 'the intro above the six numbered first-visit steps', 'status' => 'moved:Your first visit, step by step.' ],
			[ 'id' => 'services', 'where' => 'the intro above the four service cards', 'status' => 'moved:Care that fits every chapter of your smile.' ],
			[ 'id' => 'reviews', 'where' => 'the heading above the sliding patient-reviews strip', 'status' => 'dead' ],
			[ 'id' => 'faq', 'where' => 'the heading above the questions-and-answers list', 'status' => 'moved:Frequently asked questions.' ],
			[ 'id' => 'membership', 'where' => 'the no-insurance membership panel near the bottom (the small line and the paragraph; the bold line is part of the design)', 'status' => 'live' ],
		],
	],

	89 => [ // Our Office
		'route'    => '/our-office/',
		'kind'     => 'blocks',
		'liveTabs' => [ 'Hero', 'Images (all nine photos on this page are edited there)', 'Section copy (the “tour”, “technology” and “expect” rows)', 'Page sections (the map area and the questions list)', 'Bottom of page (the consultation invite)' ],
		'faq'      => 'inert',
		'images'   => [
			[ 'slot' => 'heroPrimary', 'where' => 'the big photo at the top of the page — the waiting-room lounge', 'status' => 'live' ],
			[ 'slot' => 'heroInset', 'where' => 'the small photo overlapping the big one at the top — the entry doors with the logo', 'status' => 'live' ],
			[ 'slot' => 'bentoA', 'where' => 'the largest tile in the photo tour grid — the reception desk', 'status' => 'live' ],
			[ 'slot' => 'bentoB', 'where' => 'the tall tile in the photo tour grid — a treatment room', 'status' => 'live' ],
			[ 'slot' => 'bentoC', 'where' => 'the tile in the photo tour grid with the glowing neon sign', 'status' => 'live' ],
			[ 'slot' => 'bentoD', 'where' => 'the tile in the photo tour grid with both doctors in the hallway', 'status' => 'live' ],
			[ 'slot' => 'bentoE', 'where' => 'the tile in the photo tour grid of a patient being welcomed at the desk', 'status' => 'live' ],
			[ 'slot' => 'techPrimary', 'where' => 'the big photo in the green technology area — the robotic implant arm in use', 'status' => 'live' ],
			[ 'slot' => 'techInset', 'where' => 'the small photo overlapping it — a hygienist polishing a patient’s teeth', 'status' => 'live' ],
		],
		'sections' => [
			[ 'id' => 'tour', 'where' => 'the intro above the photo tour grid', 'status' => 'live' ],
			[ 'id' => 'technology', 'where' => 'the heading and paragraph in the green technology area (the small label and the four pills are part of the design)', 'status' => 'live' ],
			[ 'id' => 'expect', 'where' => 'the heading above the four what-to-expect tiles (the tiles themselves are part of the design)', 'status' => 'live' ],
			[ 'id' => 'visit', 'where' => 'the address-hours-and-map area', 'status' => 'moved:How to find us.' ],
			[ 'id' => 'faq', 'where' => 'the heading above the questions-and-answers list', 'status' => 'moved:A few quick answers.' ],
		],
	],

	80 => [ // Contact
		'route'    => '/contact/',
		'kind'     => 'blocks',
		'liveTabs' => [ 'Hero (the words beside the message form at the top)', 'Section copy (only the “reach” row)', 'Page sections (the map area and the questions list)' ],
		'faq'      => 'inert',
		'images'   => [],
		'sections' => [
			[ 'id' => 'reach', 'where' => 'the intro above the four ways-to-reach-us cards (the cards themselves are part of the design)', 'status' => 'live' ],
			[ 'id' => 'visit', 'where' => 'the address-hours-and-map area', 'status' => 'moved:Right here in Parker.' ],
			[ 'id' => 'faq', 'where' => 'the intro above the questions-and-answers list', 'status' => 'moved:Before you reach out.' ],
		],
	],

	92 => [ // Referral Program
		'route'    => '/referral-program/',
		'kind'     => 'blocks',
		'liveTabs' => [ 'Hero', 'Images (the top photo)', 'Section copy (only the “program” row)', 'Page sections', 'Bottom of page (the booking-strip sentence only)' ],
		'faq'      => 'inert',
		'images'   => [
			[ 'slot' => 'heroPrimary', 'where' => 'the big photo to the right of the headline at the top — a patient checking in at the front desk', 'status' => 'live' ],
		],
		'sections' => [
			[ 'id' => 'program', 'where' => 'the heading and first paragraph beside the $50 + $50 reward card (the card itself is part of the design)', 'status' => 'live' ],
			[ 'id' => 'how-it-works', 'where' => 'the intro above the three numbered steps', 'status' => 'moved:Three easy steps, no paperwork.' ],
			[ 'id' => 'faq', 'where' => 'the heading above the questions-and-answers list', 'status' => 'moved:Quick answers before you refer.' ],
		],
	],

	// ── Pages with no hero group and no Page sections: photos and/or Section
	//    copy are still live on every one of them, which is why they are here.
	//    The three landing pages are paid-campaign creative — see their note.

	81 => [ // Cosmetic Dentistry LP
		'route'    => '/cosmetic-dentistry-lp/',
		'kind'     => 'template',
		'liveTabs' => [ 'Images' ],
		'orientationNote' => 'This is a paid-campaign landing page. It is hidden from Google and from the site menus on purpose, and an ad campaign points at it — so its WORDS are fixed in the page design and are not edited from these tabs. The photos below are yours to swap.',
		'images'   => [
			[ 'slot' => 'imgHeroTilted',   'where' => 'the tilted three-quarter smile photo in the group at the very top (on a phone this is the ONLY hero photo shown)', 'status' => 'live' ],
			[ 'slot' => 'imgHeroDaylight', 'where' => 'the daylight smile photo in the group at the very top', 'status' => 'live' ],
			[ 'slot' => 'imgBrushFront',   'where' => 'the veneer-being-hand-finished photo in the group at the very top', 'status' => 'live' ],
			[ 'slot' => 'imgAnnMarie',     'where' => "Ann Marie Muscarello's before/after photo in the patient results area", 'status' => 'live' ],
			[ 'slot' => 'imgBrittany',     'where' => "Brittany Fanning's before/after photo in the patient results area", 'status' => 'live' ],
			[ 'slot' => 'storyMore',       'where' => 'the photo on the first patient story card', 'status' => 'live' ],
			[ 'slot' => 'storyConfidence', 'where' => 'the photo on the second patient story card', 'status' => 'live' ],
			[ 'slot' => 'storyLength',     'where' => 'the photo on the third patient story card', 'status' => 'live' ],
			[ 'slot' => 'logoAACD',   'where' => 'the American Academy of Cosmetic Dentistry logo in the scrolling credentials strip', 'status' => 'live' ],
			[ 'slot' => 'logoAAID',   'where' => 'the American Academy of Implant Dentistry logo in the credentials strip', 'status' => 'live' ],
			[ 'slot' => 'logoFAM',    'where' => 'the Full Arch Masters logo in the credentials strip', 'status' => 'live' ],
			[ 'slot' => 'logoYomi',   'where' => 'the Yomi Robotics logo in the credentials strip', 'status' => 'live' ],
			[ 'slot' => 'logoADA',    'where' => 'the American Dental Association logo in the credentials strip', 'status' => 'live' ],
			[ 'slot' => 'logoGoogle', 'where' => 'the Google Reviews logo in the credentials strip', 'status' => 'live' ],
			[ 'slot' => 'imgRichardson',   'where' => 'the portrait of Dr. Richardson in the meet-the-dentist area', 'status' => 'live' ],
			[ 'slot' => 'imgBrush',        'where' => 'the photo in the closing section at the bottom of the page', 'status' => 'live' ],
		],
	],

	86 => [ // General LP
		'route'    => '/general-lp/',
		'kind'     => 'template',
		'liveTabs' => [ 'Images' ],
		'orientationNote' => 'This is a paid-campaign landing page. It is hidden from Google and from the site menus on purpose, and an ad campaign points at it — so its WORDS are fixed in the page design and are not edited from these tabs. The photos below are yours to swap.',
		'images'   => [
			[ 'slot' => 'imgConsult',    'where' => 'the photo beside the headline at the very top — a smiling patient', 'status' => 'live' ],
			[ 'slot' => 'imgLounge',     'where' => 'the waiting-lounge photo further down the page', 'status' => 'live' ],
			[ 'slot' => 'imgHygienist',  'where' => 'the photo of a hygienist doing a cleaning', 'status' => 'live' ],
			[ 'slot' => 'logoAACD',   'where' => 'the American Academy of Cosmetic Dentistry logo in the scrolling credentials strip', 'status' => 'live' ],
			[ 'slot' => 'logoAAID',   'where' => 'the American Academy of Implant Dentistry logo in the credentials strip', 'status' => 'live' ],
			[ 'slot' => 'logoFAM',    'where' => 'the Full Arch Masters logo in the credentials strip', 'status' => 'live' ],
			[ 'slot' => 'logoYomi',   'where' => 'the Yomi Robotics logo in the credentials strip', 'status' => 'live' ],
			[ 'slot' => 'logoADA',    'where' => 'the American Dental Association logo in the credentials strip', 'status' => 'live' ],
			[ 'slot' => 'logoGoogle', 'where' => 'the Google Reviews logo in the credentials strip', 'status' => 'live' ],
			[ 'slot' => 'imgRichardson', 'where' => 'the portrait of Dr. Richardson in the meet-the-dentist area', 'status' => 'live' ],
			[ 'slot' => 'imgDrPortrait', 'where' => 'the photo in the closing section at the bottom of the page', 'status' => 'live' ],
		],
	],

	97 => [ // Veneers LP
		'route'    => '/veneers-lp/',
		'kind'     => 'template',
		'liveTabs' => [ 'Images' ],
		'orientationNote' => 'This is a paid-campaign landing page. It is hidden from Google and from the site menus on purpose, and an ad campaign points at it — so its WORDS are fixed in the page design and are not edited from these tabs. The photos below are yours to swap.',
		'images'   => [
			[ 'slot' => 'imgHeroSide',   'where' => 'the side-view smile photo in the group at the very top', 'status' => 'live' ],
			[ 'slot' => 'imgHeroBerry',  'where' => 'the tilted three-quarter smile photo in the group at the very top', 'status' => 'live' ],
			[ 'slot' => 'imgHeroMacro',  'where' => 'the straight-on close-up smile photo in the group at the very top', 'status' => 'live' ],
			[ 'slot' => 'imgAnnMarie',   'where' => "Ann Marie Muscarello's before/after photo in the patient results area", 'status' => 'live' ],
			[ 'slot' => 'imgBrittany',   'where' => "Brittany Fanning's before/after photo in the patient results area", 'status' => 'live' ],
			[ 'slot' => 'storyMore',       'where' => 'the photo on the first patient story card', 'status' => 'live' ],
			[ 'slot' => 'storyConfidence', 'where' => 'the photo on the second patient story card', 'status' => 'live' ],
			[ 'slot' => 'storyLength',     'where' => 'the photo on the third patient story card', 'status' => 'live' ],
			[ 'slot' => 'logoAACD',   'where' => 'the American Academy of Cosmetic Dentistry logo in the scrolling credentials strip', 'status' => 'live' ],
			[ 'slot' => 'logoAAID',   'where' => 'the American Academy of Implant Dentistry logo in the credentials strip', 'status' => 'live' ],
			[ 'slot' => 'logoFAM',    'where' => 'the Full Arch Masters logo in the credentials strip', 'status' => 'live' ],
			[ 'slot' => 'logoYomi',   'where' => 'the Yomi Robotics logo in the credentials strip', 'status' => 'live' ],
			[ 'slot' => 'logoADA',    'where' => 'the American Dental Association logo in the credentials strip', 'status' => 'live' ],
			[ 'slot' => 'logoGoogle', 'where' => 'the Google Reviews logo in the credentials strip', 'status' => 'live' ],
			[ 'slot' => 'imgRichardson', 'where' => 'the portrait of Dr. Richardson in the meet-the-dentist area', 'status' => 'live' ],
			[ 'slot' => 'imgEditorial',  'where' => 'the photo in the closing section at the bottom of the page', 'status' => 'live' ],
		],
	],

	91 => [ // Privacy Policy
		'route'    => '/privacy-policy/',
		'kind'     => 'template',
		'liveTabs' => [ 'Section copy' ],
		'orientationNote' => 'The whole policy is written in the Section copy rows below — each row is one numbered part of the document, in the order it appears. There is no hero and no photo on this page.',
		'sectionsNote' => 'THIS IS A LEGAL DOCUMENT. It says what the practice does with patient information, so please do not reword it casually — have whoever handles your HIPAA compliance approve any change first. Fixing a phone number or an address here is fine.',
		'sections' => [],
	],

	95 => [ // Terms & Conditions
		'route'    => '/terms-conditions/',
		'kind'     => 'template',
		'liveTabs' => [ 'Section copy' ],
		'orientationNote' => 'The whole document is written in the Section copy rows below — each row is one numbered part, in the order it appears. There is no hero and no photo on this page.',
		'sectionsNote' => 'THIS IS A LEGAL DOCUMENT. Please do not reword it casually — have it approved before changing anything beyond a phone number or an address.',
		'sections' => [],
	],

	96 => [ // Thank you
		'route'    => '/thank-you/',
		'kind'     => 'template',
		'liveTabs' => [ 'Images' ],
		'orientationNote' => 'The page someone lands on after sending a form. Its words are part of the page design and are not edited here; the background photo is.',
		'images'   => [
			[ 'slot' => 'heroBg', 'where' => 'the full-width background photo behind the thank-you message — the team walking together outside the practice', 'status' => 'live' ],
		],
	],

	344 => [ // 404
		'route'    => '/404-2/',
		'kind'     => 'template',
		'liveTabs' => [ 'Images' ],
		'orientationNote' => 'The page someone sees at a web address that does not exist. Its words are part of the page design and are not edited here; the background photo is.',
		'images'   => [
			[ 'slot' => 'heroBg', 'where' => 'the full-width background photo — the neon smiley sign at the practice entrance', 'status' => 'live' ],
		],
	],

	346 => [ // Blog index
		'route'    => '/blog/',
		'kind'     => 'template',
		'liveTabs' => [],
		'orientationNote' => 'This page is just the list of your blog posts. It builds itself from Posts in the left-hand menu, newest first. To change what appears here, write a new post or edit an existing one — it shows up on this list at the next site build.',
	],

];

/**
 * The map entry for the page being edited, or null for a page the maps do not
 * know — which is the signal to leave every message exactly as it is.
 *
 * get_the_ID() is enough on the edit screen (the global post is set up before
 * any meta box renders); the query-string fallback covers the odd admin
 * request that renders fields before the loop.
 */
function current_page(): ?array {
	$id = get_the_ID();

	if ( ! is_int( $id ) || $id <= 0 ) {
		// Read-only routing on an admin id; nothing here is saved or echoed.
		$id = isset( $_GET['post'] ) ? (int) $_GET['post'] : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	}

	if ( isset( PAGES[ $id ] ) ) {
		return PAGES[ $id ];
	}

	return new_page( $id );
}

/**
 * The entry for a page the maps do not know — which in practice means a page
 * the CLIENT made, since the 33 that existed at handover are all mapped.
 *
 * This was the one screen with no guidance at all, and it is the only screen
 * they will ever create. Worse, the default message it fell back to named two
 * tabs that do nothing here: it said to use <em>Bottom of page</em> for the
 * consultation invite and that <em>Images</em> "works on every page".
 *
 * A new page is rendered by src/pages/[...slug].astro, and that file decides
 * what is true here. It has no reference to `closing` or FinalBand anywhere, so
 * Bottom of page draws nothing; and its own comment at :184 records that this
 * route "never reads `page.images`". Everything else does work — the hero (read
 * at :258 and used at :276-279), Page sections, and the five legacy tabs, plus
 * the ordinary WordPress editor box, which on this route is the only place a
 * photo can go outside a section.
 *
 * Returns null for anything that is not a page, so posts and testimonials are
 * untouched.
 */
function new_page( int $id ): ?array {
	if ( $id <= 0 || 'page' !== get_post_type( $id ) ) {
		return null;
	}

	// Only a published page has a real address. A draft's permalink is a
	// query-string preview URL whose path is "/", and linking that would send
	// the editor to the home page under the words "open this page".
	$route = 'publish' === get_post_status( $id )
		? (string) wp_parse_url( (string) get_permalink( $id ), PHP_URL_PATH )
		: '';

	return [
		'route'    => $route,
		'kind'     => 'new',
		// Everything [...slug].astro actually reads. Images and Bottom of page
		// are deliberately absent, which is what hides them.
		'liveTabs' => [ 'Hero', 'Page sections', 'Section copy', 'Process', 'Cards & lists', 'FAQ', 'On this page' ],
	];
}

/**
 * Whether a tab is in this page's live list. Entries may carry a qualifier in
 * parentheses, so match the bare label or the label followed by a space.
 */
function tab_is_live( array $page, string $label ): bool {
	foreach ( $page['liveTabs'] as $tab ) {
		if ( $tab === $label || 0 === strpos( $tab, $label . ' ' ) ) {
			return true;
		}
	}

	return false;
}

/**
 * The live tabs as a sentence fragment: "Hero, Images and Bottom of page".
 */
function tabs_sentence( array $page ): string {
	$tabs = array_map( 'esc_html', $page['liveTabs'] ?? [] );

	// A guard, not a live path: orientation_message() now sends a page with no
	// live tabs down its own branch and never reaches this. It stays because
	// without it array_pop() on an empty list returns null and the sentence
	// reads "edited from these tabs: ." — which is how the empty case was
	// found in the first place.
	if ( ! $tabs ) {
		return 'none';
	}

	$last = array_pop( $tabs );

	return $tabs ? implode( ', ', $tabs ) . ' and ' . $last : (string) $last;
}

/**
 * Split a status into its state and, for a moved one, the target section's
 * heading — trimmed of its trailing period so it reads cleanly inside quotes.
 */
function parse_status( string $status ): array {
	if ( 0 === strpos( $status, 'moved:' ) ) {
		return [ 'moved', rtrim( trim( substr( $status, 6 ) ), '.' ) ];
	}

	return [ $status, '' ];
}

/**
 * Whether any row in a guide list is not plainly live. When every row is live
 * the guide skips the per-row "Live" tag and says so once at the bottom.
 */
function list_is_mixed( array $rows ): bool {
	foreach ( $rows as $row ) {
		if ( 'live' !== $row['status'] ) {
			return true;
		}
	}

	return false;
}

/**
 * One guide line: the code, what the visitor sees there, and the status. Only
 * inline tags, because the images guide travels through instruction text,
 * which is filtered harder than a message body.
 */
function guide_line( array $row, string $code_key, bool $mixed, bool $is_photo ): string {
	$where = rtrim( trim( $row['where'] ), '.' );

	list( $state, $target ) = parse_status( $row['status'] );

	$line = '<strong>' . esc_html( $row[ $code_key ] ) . '</strong> — ' . esc_html( $where ) . '.';

	if ( 'moved' === $state ) {
		$verb  = $is_photo ? 'changed' : 'edited';
		$line .= ' <em>Now ' . $verb . ' in Page sections — open the section “' . esc_html( $target ) . '”.</em>';
	} elseif ( 'dead' === $state ) {
		$line .= ' <em>Part of the design now — this row saves, but changes nothing; ask us if it should say something different.</em>';
	} elseif ( 'unused' === $state ) {
		// Distinct from 'dead': a dead row's picture was absorbed into the
		// design, so something is still on the page. An unused row's code is
		// not read anywhere — whatever is in it has never appeared.
		$line .= ' <em>Nothing on the site uses this row — the picture in it has never appeared. '
			. 'Tell us if it should.</em>';
	} elseif ( $mixed ) {
		$line .= $is_photo ? ' <em>Live — swap it here.</em>' : ' <em>Live — edit it here.</em>';
	}

	return $line;
}

/**
 * A link that opens this page on the real website, in a new tab.
 *
 * The point of the whole screen is that it mirrors the front end, and the
 * cheapest way to make a mirror obvious is to put the thing it mirrors one
 * click away — so an editor can hold the page and its boxes side by side
 * instead of guessing which box is which.
 *
 * The host comes from VS_FRONTEND_URL, the one place the CMS is told where the
 * site lives, so this follows the domain cutover without an edit here. If the
 * constant is unset the link is simply not drawn: a guess at the host would be
 * a second place for it to go stale, which is the rule the phone number and
 * the booking link are already held to.
 */
function view_on_site_link( array $page ): string {
	if ( ! defined( 'VS_FRONTEND_URL' ) || ! VS_FRONTEND_URL ) {
		return '';
	}

	$route = $page['route'] ?? '';

	if ( '' === $route ) {
		return '';
	}

	$url = rtrim( (string) VS_FRONTEND_URL, '/' ) . $route;

	return '<a href="' . esc_url( $url ) . '" target="_blank" rel="noopener">'
		. 'Open this page on the website ↗</a> — the boxes below are in the same '
		. 'order as the page itself, top to bottom.';
}

/**
 * Rewrite 1: the orientation message above the tabs, per page.
 */
function orientation_message( array $page ): string {
	$lines = [];

	if ( 'home' === $page['kind'] ) {
		$lines[] = '<strong>Almost all of this page is yours to edit.</strong>';
		$lines[] = 'The headline area at the very top is managed by us for now — the note on the <em>Hero</em> tab explains. '
			. 'Everything below it is edited from two tabs: the words in <em>Section copy</em>, the photos in <em>Images</em>. '
			. 'Each of those tabs starts with a guide saying exactly which box changes which part of the page.';
		$lines[] = 'The other tabs save, but do not change this page. Changes go live on the next site build.';
	} elseif ( 'new' === $page['kind'] ) {
		$lines[] = '<strong>This is a page you made, so it starts empty.</strong>';
		$lines[] = 'Give it a headline on the <em>Hero</em> tab, then build the rest in '
			. '<em>Page sections</em>: press <em>Add a section</em>, choose what kind of section it is, '
			. 'and fill in the boxes. Photos go inside the section you add, or in the big editor box '
			. 'under the title.';
		$lines[] = 'Two tabs do nothing on a page like this and are hidden: the closing invite and the '
			. 'page-wide photo list are both part of the designs we hand-built, and a new page does not '
			. 'have one yet. Ask us if this page needs either.';
		$lines[] = '<strong>A new page is not in any menu until someone puts it there</strong> — '
			. 'Appearance → Menus. Until then it is only reachable by its address.';
		$lines[] = 'Changes go live on the next site build.';
	} elseif ( 'template' === $page['kind'] && empty( $page['liveTabs'] ) ) {
		// A page with no live tabs at all — today only the blog index, which
		// builds itself from Posts. The branch below would open with "edited
		// from these tabs: none", a sentence an editor has to decode, and then
		// answer two questions this page does not raise: what "the whole list"
		// refers to, and why Page sections must stay empty. It gets a short
		// branch of its own instead, and its note carries the rest.
		$lines[] = '<strong>There is nothing to edit on this screen.</strong>';

		if ( ! empty( $page['orientationNote'] ) ) {
			$lines[] = esc_html( $page['orientationNote'] );
		}

		$lines[] = 'The tabs below do still save when you press Update — they simply do not reach the site.';
	} elseif ( 'template' === $page['kind'] ) {
		$lines[] = '<strong>This page is edited from these tabs: ' . tabs_sentence( $page ) . '.</strong>';
		$lines[] = 'That is the whole list — a box on any other tab saves, but does not change this page. '
			. '<em>Page sections</em> is empty here on purpose: this page still runs on its designed layout, '
			. 'so please leave it empty — we move pages over ourselves.';

		// A page's own note, when it has one — the three campaign landing pages
		// say their words are ad creative, the two legal pages say the rows ARE
		// the document, the blog index says it builds itself. This used to be
		// read only on the `blocks` branch, so every note on a template page
		// was written and never shown: the project's most-repeated defect,
		// caught here by rendering the message rather than reading the code.
		if ( ! empty( $page['orientationNote'] ) ) {
			$lines[] = esc_html( $page['orientationNote'] );
		}

		// Promise only the guides this page actually has. Naming the Images
		// guide on a page with no photos, or the Section copy guide on a page
		// with no rows, sends an editor to look for something that is not there.
		$guides = [];

		if ( ! empty( $page['images'] ) ) {
			$guides[] = 'The <em>Images</em> tab starts with a guide saying where each photo appears';
		}

		if ( ! empty( $page['sections'] ) || ! empty( $page['sectionsNote'] ) ) {
			$guides[] = ( $guides ? 'and <em>Section copy</em> with one saying which words sit where' : 'The <em>Section copy</em> tab starts with a note about these rows' );
		}

		$lines[] = ( $guides ? implode( ', ', $guides ) . '. ' : '' ) . 'Changes go live on the next site build.';
	} else {
		$lines[] = '<strong>The words visitors read on this page are edited in <em>Page sections</em> — '
			. 'one row per part of the page, top to bottom.</strong>';

		if ( ! empty( $page['images'] ) ) {
			$lines[] = 'For photos, open the <em>Images</em> tab and read the guide at the top first — it says '
				. 'where each photo appears, and which photos are changed inside <em>Page sections</em> these days.';
		}

		$lines[] = 'The full list of tabs that change this page: ' . tabs_sentence( $page ) . '. '
			. 'A box on any other tab saves, but does not change the page.';

		if ( ! empty( $page['orientationNote'] ) ) {
			$lines[] = esc_html( $page['orientationNote'] );
		}

		$lines[] = 'Changes go live on the next site build.';
	}

	$link = view_on_site_link( $page );

	if ( '' !== $link ) {
		$lines[] = $link;
	}

	return implode( "\n", $lines );
}

/**
 * Rewrite 2: the Hero tab message on a page whose hero the site does not read.
 */
function hero_message(): string {
	return '<strong>On this page the top area is managed by us.</strong>' . "\n"
		. 'The big headline, the small line above it, the sentence under it and the buttons are hand-built '
		. 'into this page’s design — the boxes below save, but do not change the page. If the top of the page '
		. 'should say something different, tell us and we will change it, or connect these boxes so they work '
		. 'here the way they do on the other pages.' . "\n"
		. 'Everything below the headline area is yours — the note at the very top of this screen says which tabs to use.';
}

/**
 * Rewrite 3: the guide at the top of the Images tab.
 */
function images_guide( array $page ): string {
	$mixed = list_is_mixed( $page['images'] );

	$lines   = [];
	$lines[] = 'Where each photo appears on the page — match the code in the <em>Photo code</em> column.';

	foreach ( $page['images'] as $row ) {
		$lines[] = guide_line( $row, 'slot', $mixed, true );
	}

	if ( ! empty( $page['imagesNote'] ) ) {
		$lines[] = esc_html( $page['imagesNote'] );
	}

	$footer = 'To swap a photo, change the Image and leave the code alone — it ties the photo to its place.';

	if ( $mixed ) {
		$footer .= ' Keep every row, even one whose photo is changed in Page sections these days: the site '
			. 'still checks the row exists, and deleting one can stop the next build.';
	} else {
		$footer .= ' Keep every row — deleting one can stop the next build.';
	}

	$footer .= ' Alt text describes the picture for screen readers and search engines; it is worth writing '
		. 'properly on every photo.';

	$lines[] = $footer;

	return implode( '<br>', $lines );
}

/**
 * Rewrite 4: the guide at the top of the Section copy tab.
 */
function sections_guide( array $page ): string {
	$mixed = list_is_mixed( $page['sections'] );

	$lines = [];

	// A page can carry a note and NO per-row list — the two legal pages do,
	// where the rows are a document rather than page furniture and naming 34 or
	// 45 of them individually would help nobody. Emitting the "where each row
	// appears" header above an empty list, and "every row above is live" below
	// it, is what this guard prevents.
	if ( empty( $page['sections'] ) ) {
		if ( ! empty( $page['sectionsNote'] ) ) {
			return esc_html( $page['sectionsNote'] );
		}

		return '';
	}

	$lines[] = '<strong>Where each row below appears on the page — match its Section code box.</strong>';

	foreach ( $page['sections'] as $row ) {
		$lines[] = guide_line( $row, 'id', $mixed, false );
	}

	if ( $mixed ) {
		$lines[] = 'Edit the words in the rows marked live, and leave each Section code exactly as it is. '
			. 'A row marked “now edited in Page sections” still saves here, but the site reads the matching '
			. 'section there instead.';
	} else {
		$lines[] = 'Every row above is live: edit the words, and leave each Section code exactly as it is.';
	}

	// The same escape hatch the images guide has.
	if ( ! empty( $page['sectionsNote'] ) ) {
		$lines[] = esc_html( $page['sectionsNote'] );
	}

	return implode( "\n", $lines );
}

/**
 * Rewrite 5: the FAQ tab, on pages whose visible questions moved into Page
 * sections. Two flavours, because the pages differ in one honest detail: on
 * most of them the old list still feeds what Google shows for the page, so an
 * edit in Page sections should be mirrored here; on the rest the old list is
 * simply done.
 */
function faq_instructions( string $mode ): string {
	$note = 'The questions visitors see on this page are edited in <em>Page sections</em> now — open the '
		. 'questions-and-answers section there.';

	if ( 'mirror' === $mode ) {
		return $note . ' The list below still feeds what Google shows for this page in its search results, '
			. 'so after changing a question there, make the same change here to keep the two matching.';
	}

	return $note . ' The list below saves, but the site no longer reads it.';
}

/**
 * The one entry point. Runs at priority 20, after vs-content-model.php’s own
 * prepare filters: its hide filter may hand us `false` for a retired field,
 * which passes straight through, and the one field both files rewrite — the
 * photo code box — is guarded on opposite conditions, so the two can never
 * disagree about the same row.
 */
function rewrite_guidance( $field ) {
	if ( ! is_array( $field ) ) {
		return $field;
	}

	$page = current_page();

	if ( null === $page ) {
		return $field;
	}

	switch ( $field['key'] ?? '' ) {
		case 'field_vs_page_intro':
			$field['message'] = orientation_message( $page );
			break;

		case 'field_vs_hero_intro':
			if ( ! tab_is_live( $page, 'Hero' ) ) {
				$field['message'] = hero_message();
			}
			break;

		case 'field_vs_images':
			if ( ! empty( $page['images'] ) ) {
				$field['instructions'] = images_guide( $page );
			}
			break;

		case 'field_vs_image_slot':
			// Only a row that already holds a code, and only on a page whose
			// guide exists to point at. A blank row keeps the unlock message
			// from vs-content-model.php, which is the right one there: it
			// explains that a code is created on save.
			if ( ! empty( $page['images'] ) && '' !== trim( (string) ( $field['value'] ?? '' ) ) ) {
				$field['instructions'] = 'Filled in automatically — this code ties the photo to its place, and '
					. 'the guide above says where each one appears. To change a photo, change the Image, '
					. 'not this code.';
			}
			break;

		case 'field_vs_sections_note':
			if ( ! empty( $page['sections'] ) || ! empty( $page['sectionsNote'] ) ) {
				$field['message'] = sections_guide( $page );
			}
			break;

		case 'field_vs_faqs':
			if ( ! empty( $page['faq'] ) ) {
				$field['instructions'] = faq_instructions( $page['faq'] );
			}
			break;

		case 'field_vs_closing_intro':
			// Appended, not replaced: the stored message explains the switch and
			// which boxes belong to which band, which is still the right
			// explanation — it just never said what the words currently are.
			$id = isset( $_GET['post'] ) ? (int) $_GET['post'] : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

			if ( ! $id && isset( $GLOBALS['post']->ID ) ) {
				$id = (int) $GLOBALS['post']->ID;
			}

			$today = $id ? closing_today( $id ) : '';

			if ( '' !== $today ) {
				$field['message'] = ( $field['message'] ?? '' ) . $today;
			}
			break;
	}

	return $field;
}
add_filter( 'acf/prepare_field', __NAMESPACE__ . '\\rewrite_guidance', 20 );

/**
 * ─── The Page sections row handles ──────────────────────────────────────────
 *
 * A collapsed row in Page sections says only what KIND of section it is:
 * "Photo and copy", "Card grid", "Process steps". On a page with fourteen rows
 * that is fourteen handles naming a shape, and none of them naming a place. An
 * editor told "the bit about sedation is wrong" has to open rows one at a time
 * until they find it, and the two "Photo and copy" rows look identical until
 * they are open. The names are also the wrong vocabulary to search with: the
 * client thinks in headings, because a heading is what they read on the page.
 *
 * So each handle gains its own section's heading:
 *
 *     Photo and copy: Sedation Options for Anxious Patients
 *
 * ADMIN CHROME ONLY. This filter runs while ACF draws the edit screen. It
 * stores nothing, changes no field value, and is invisible to WPGraphQL and
 * therefore to the Astro build — the failure mode of getting it wrong is an
 * ugly row handle, not a wrong page.
 *
 * PLAIN TEXT, NO MARKUP. ACF passes the finished title through its own escaper
 * before printing it, and which tags survive that has changed between ACF
 * versions. A heading may legitimately contain `<em>`, so it is stripped here
 * rather than gambled on.
 */

/** How much of a heading fits in a row handle before it starts wrapping. */
const HANDLE_CHARS = 56;

/**
 * Trim to HANDLE_CHARS on a word boundary, with an ellipsis when it was cut.
 * Falls back to a hard cut for a single word longer than the budget.
 */
function shorten_handle( string $text ): string {
	$text = trim( preg_replace( '/\s+/u', ' ', $text ) );

	if ( '' === $text || mb_strlen( $text ) <= HANDLE_CHARS ) {
		return $text;
	}

	$cut   = mb_substr( $text, 0, HANDLE_CHARS );
	$space = mb_strrpos( $cut, ' ' );

	if ( false !== $space && $space > 20 ) {
		$cut = mb_substr( $cut, 0, $space );
	}

	return rtrim( $cut, " ,.;:—-" ) . '…';
}

/**
 * The human label a `band_key` value stands for, read off the layout's own
 * select rather than the constant in vs-content-model.php — the two files are
 * deployed separately, and a lookup that spans them can go stale in the window
 * where only one has been uploaded.
 *
 * The labels end in the page they belong to, as in "… (Emergency Dentistry)".
 * That is useful in the dropdown, where every section on the site is listed
 * together, and noise in a handle on the very page it names.
 */
function code_band_label( array $layout, string $value ): string {
	foreach ( ( $layout['sub_fields'] ?? [] ) as $sub ) {
		if ( 'band_key' !== ( $sub['name'] ?? '' ) ) {
			continue;
		}

		$label = (string) ( $sub['choices'][ $value ] ?? '' );

		return trim( preg_replace( '/\s*\([^()]*\)\s*$/u', '', $label ) );
	}

	return '';
}

/**
 * What this row should say it is, or '' to leave the handle alone.
 *
 * Order matters. A built-in section has no heading of its own — its wording
 * lives in the design — so its picker is the only thing that identifies it, and
 * is checked first. Otherwise the heading is what the visitor reads and what
 * the editor will think of the section by. `nav_label` is the last resort: a
 * section can legitimately have no heading (a band that opens with a photo),
 * and its side-menu name is then the only words attached to it.
 */
function row_summary( array $layout ): string {
	$band_key = get_sub_field( 'band_key' );

	if ( is_string( $band_key ) && '' !== $band_key ) {
		$label = code_band_label( $layout, $band_key );

		if ( '' !== $label ) {
			return shorten_handle( $label );
		}
	}

	foreach ( [ 'heading', 'nav_label' ] as $name ) {
		$value = get_sub_field( $name );

		if ( ! is_string( $value ) ) {
			continue;
		}

		// wp_strip_all_tags() rather than a regex: the heading field documents
		// `<em>` and an editor may well type something else in there too.
		$value = shorten_handle( wp_strip_all_tags( $value ) );

		if ( '' !== $value ) {
			return $value;
		}
	}

	return '';
}

/**
 * Append the summary to the layout's name. ACF sets up the row's loop context
 * before firing this, which is what makes get_sub_field() work here.
 *
 * A row still being added has no values yet, so `row_summary()` returns '' and
 * the handle keeps the bare layout name until the editor types a heading.
 */
function layout_title( $title, $field, $layout, $i ) {
	if ( ! is_array( $layout ) ) {
		return $title;
	}

	$summary = row_summary( $layout );

	if ( '' === $summary ) {
		return $title;
	}

	// A colon, not a dash: several of the built-in sections' labels contain an
	// em dash of their own ("Service area — Parker map, address and
	// directions"), and a dash joiner turns those into two dashes competing to
	// be the separator.
	return $title . ': ' . $summary;
}
add_filter( 'acf/fields/flexible_content/layout_title/name=blocks', __NAMESPACE__ . '\\layout_title', 10, 4 );

/**
 * ─── Grouping a section's boxes so they mirror the section ──────────────────
 *
 * A section row is one flat list of boxes: thirty of them for "Photo and copy",
 * twenty-three for "Comparison cards". Nothing in that list says which box is
 * the heading you can see, which is the panel underneath it, and which three
 * you should never touch. Allan's words, and they are the right test: "the
 * fields of each page will be like a mirror on the front end."
 *
 * So the boxes are re-grouped under headings that walk down the section the way
 * a visitor's eye does — the heading at the top, then the cards, then the panel
 * under them, then the buttons — with every non-content control collected into
 * one closing group the editor can ignore.
 *
 * WHY THIS LIVES HERE AND RUNS ON `acf/prepare_field`.
 *
 * The obvious place to reorder sub-fields is where they are declared, in
 * vs-content-model.php. That file is the GraphQL contract: 37 block types and
 * 325 fields that all 48 routes are built from, and this project's most
 * expensive failures have been schema-shaped. `acf/prepare_field` runs only
 * when wp-admin DRAWS a field. It is not part of registering the schema and not
 * part of resolving a query, so nothing done here can reach WPGraphQL — the
 * separation is structural rather than careful.
 *
 * That was checked rather than assumed. Against the live endpoint, `PageFields`
 * exposes exactly nine fields — blocks, cards, closing, faqs, hero, images,
 * processSteps, sections, tocLinks — and NONE of the model's five `tab` fields
 * or its `message` fields appear anywhere in the schema. WPGraphQL for ACF
 * skips field types that carry no value, which is the class an `accordion`
 * belongs to. A fingerprint of all 37 block types and their 325 fields was
 * taken before this filter existed, and is re-taken after: identical, or this
 * comes out.
 *
 * NOTHING CAN VANISH. The plan lists field NAMES, not keys — the names are
 * identical across layouts by design, which is what lets one entry describe the
 * preamble everywhere. Any field the plan does not mention falls through to the
 * closing settings group rather than being dropped, so a field added to a
 * layout later appears in wp-admin whether or not anyone updates this table.
 * Ordering is presentation only: ACF stores values by key, so a reordered row
 * loads and saves exactly as before.
 */

/**
 * layout name → the groups, in the order the section renders.
 *
 * Each group is [ heading, [ field names, in order ] ]. The closing settings
 * group is added automatically and holds everything not named here.
 *
 * HOW THE ORDER WAS ESTABLISHED. One agent per layout read the layout's PHP and
 * its Astro component's template together and reported the order things are
 * actually drawn in, citing line numbers; a second, adversarial agent per
 * layout re-derived that order from the component and tried to fault it. Eleven
 * of thirteen came back with corrections, which is the point of running the
 * second pass — the most useful was MediaSplit, where the obvious reading puts
 * the section's `body` at the top with the heading, and the component draws it
 * at MediaSplitBlock.astro:807, inside `.prose`, AFTER the quote and the
 * checklist. It is grouped with the words beside the photo for that reason.
 *
 * THE FIELD LISTS ARE THE SCHEMA'S, NOT A GREP'S. The first attempt built them
 * by regex over this repo and silently missed every field `block_preamble()`
 * contributes, because those keys are concatenated (`$k . 'anchor'`) rather
 * than written out — so `eyebrow`, `heading` and `body` were absent from twelve
 * of thirteen plans and would have fallen through into the settings group, one
 * step short of putting a page's own heading under "you can usually leave these
 * alone". The lists were re-taken from the live GraphQL schema, which enumerates
 * exactly the fields each layout has: 244 across sixteen layouts.
 *
 * CHECKED MECHANICALLY, per layout: no field named twice, no field named that
 * the layout does not have, and nothing left to fall through except things that
 * really are settings — background, menu name, jump-to name, column counts,
 * heading width, stacking width, photo side, panel position.
 *
 * `code_section` is deliberately absent. It has three fields; grouping them
 * would add furniture, not remove it.
 */
const SECTION_GROUPS = [
	'faq' => [
		[ 'The heading at the top', [ 'eyebrow', 'heading', 'body' ] ],
		[ 'The questions', [ 'items' ] ],
		[ 'The note beside the questions', [ 'pull' ] ],
		[ 'The buttons at the end', [ 'cta_label', 'cta_href', 'cta_hover', 'cta_phone_first' ] ],
	],
	'card_grid' => [
		[ 'The heading at the top', [ 'eyebrow', 'heading', 'body' ] ],
		[ 'The cards across the middle', [ 'cards_eyebrow', 'cards' ] ],
		[ 'The panel under the cards', [ 'callout_eyebrow', 'callout_heading', 'callout_body', 'callout_points' ] ],
		[ 'The buttons at the end', [ 'cta_label', 'cta_href', 'cta_hover', 'cta_label_2', 'cta_href_2', 'cta_hover_2', 'cta_note' ] ],
	],
	'media_split' => [
		[ 'The heading at the top', [ 'eyebrow', 'heading' ] ],
		[ 'The photo', [ 'image', 'image_alt' ] ],
		[ 'The words beside the photo', [ 'quote', 'quote_attrib', 'checklist', 'body', 'body_2_heading', 'body_2', 'body_3_heading', 'body_3' ] ],
		[ 'The row of big numbers under the words', [ 'creds' ] ],
		[ 'The boxed note beside the words', [ 'callout_eyebrow', 'callout_heading', 'callout_body' ] ],
		[ 'The buttons under the words', [ 'cta_label', 'cta_href', 'cta_hover', 'cta_label_2', 'cta_href_2', 'cta_hover_2' ] ],
		[ 'The cards and closing line at the bottom', [ 'sub_cards', 'sub_foot', 'cta_text' ] ],
	],
	'process_steps' => [
		[ 'The heading at the top', [ 'eyebrow', 'heading', 'body' ] ],
		[ 'The cards above the steps', [ 'pre_cards' ] ],
		[ 'The numbered steps', [ 'steps' ] ],
		[ 'The paragraph under the steps', [ 'sub_foot' ] ],
		[ 'The line that closes the section', [ 'cta_text' ] ],
	],
	'comparison_cards' => [
		[ 'The heading at the top', [ 'eyebrow', 'heading', 'body' ] ],
		[ 'The cards across the middle', [ 'tiers' ] ],
		[ 'The panel under the cards', [ 'callout_eyebrow', 'callout_heading', 'callout_body' ] ],
		[ 'The smaller cards below the panel', [ 'alt_cards' ] ],
		[ 'The list of terms at the bottom', [ 'glossary_eyebrow', 'glossary_heading', 'glossary_body', 'glossary' ] ],
		[ 'The buttons at the end', [ 'cta_label', 'cta_href', 'cta_hover', 'cta_label_2', 'cta_href_2', 'cta_hover_2', 'cta_note', 'cta_hide' ] ],
	],
	'pricing_tiers' => [
		[ 'The heading at the top', [ 'eyebrow', 'heading', 'body' ] ],
		[ 'The price cards across the middle', [ 'plans' ] ],
		[ 'The line under the cards', [ 'note' ] ],
		[ 'The buttons at the end', [ 'cta_label', 'cta_href', 'cta_hover', 'cta_label_2', 'cta_href_2', 'cta_hover_2', 'cta_note' ] ],
	],
	'stat_callout' => [
		[ 'The big number on the left', [ 'value', 'unit', 'caption' ] ],
		[ 'The words beside the number', [ 'eyebrow', 'heading', 'intro', 'body_heading', 'body', 'intro_2' ] ],
		[ 'The list of points', [ 'points' ] ],
	],
	'copy_plus_stats' => [
		[ 'The heading at the top', [ 'eyebrow', 'heading', 'body' ] ],
		[ 'More words below that', [ 'body_2_heading', 'body_2', 'body_3_heading', 'body_3' ] ],
		[ 'The number cards beside the words', [ 'stats' ] ],
		[ 'The button at the foot of the words', [ 'cta_label', 'cta_href', 'cta_hover' ] ],
	],
	'tech_grid' => [
		[ 'The heading at the top', [ 'eyebrow', 'heading', 'body' ] ],
		[ 'The numbered cards', [ 'tech_cards' ] ],
		[ 'The words in the panel below', [ 'callout_eyebrow', 'callout_heading', 'callout_body' ] ],
		[ 'The photo in that panel', [ 'image', 'image_alt', 'image_tag' ] ],
	],
	'service_cards' => [
		[ 'The heading at the top', [ 'eyebrow', 'heading', 'body' ] ],
		[ 'The tiles across the middle', [ 'svc_cards' ] ],
		[ 'The paragraph under the tiles', [ 'sub_foot' ] ],
		[ 'The buttons at the end', [ 'cta_label', 'cta_href', 'cta_hover', 'cta_label_2', 'cta_href_2', 'cta_hover_2', 'cta_note' ] ],
	],
	'doctor_profiles' => [
		[ 'The heading at the top', [ 'eyebrow', 'heading', 'body' ] ],
		[ 'The photo down the left side', [ 'image', 'image_alt', 'pill' ] ],
		[ 'The write-ups beside the photo', [ 'bios' ] ],
	],
	'candidacy_ledger' => [
		[ 'The heading at the top', [ 'eyebrow', 'heading', 'body' ] ],
		[ 'The writing down the left side', [ 'copy_heading', 'copy_body', 'copy_heading_2', 'copy_body_2' ] ],
		[ 'The buttons under that writing', [ 'cta_label', 'cta_href', 'cta_hover', 'cta_label_2', 'cta_href_2', 'cta_hover_2' ] ],
		[ 'The checklist box on the right', [ 'ledger_eyebrow', 'ledger_heading', 'ledger' ] ],
	],
	'gallery_marquee' => [
		[ 'The heading at the top', [ 'eyebrow', 'heading', 'body', 'body_2' ] ],
		[ 'The quote under the photos', [ 'quote', 'quote_attrib' ] ],
		[ 'The buttons at the end', [ 'cta_label', 'cta_href', 'cta_hover', 'cta_label_2', 'cta_href_2', 'cta_hover_2', 'cta_note' ] ],
	],
	'callout_list' => [
		[ 'The heading at the top', [ 'eyebrow', 'heading', 'body' ] ],
		[ 'The list', [ 'lines' ] ],
	],
	'map_visit' => [
		[ 'The heading at the top', [ 'eyebrow', 'heading', 'body' ] ],
	],
];

/** The heading every layout's leftover controls are collected under. */
const SETTINGS_GROUP = 'Settings — you can usually leave these alone';

/**
 * One accordion separator. `open` on the first group only, so a row opens
 * showing the words and nothing else; `multi_expand` so opening one does not
 * shut another. `endpoint` is what makes the PREVIOUS accordion stop here —
 * without it ACF nests every following field under the first one.
 */
function group_divider( string $layout, string $title, int $i, bool $open ): array {
	return [
		'key'          => 'field_vs_grp_' . $layout . '_' . $i,
		'label'        => $title,
		'name'         => '',
		'type'         => 'accordion',
		'open'         => $open ? 1 : 0,
		'multi_expand' => 1,
		'endpoint'     => 0,
	];
}

/**
 * Re-order one layout's sub-fields into its groups and splice the accordions in.
 * Returns the sub-fields unchanged when the layout has no plan.
 */
function group_layout_fields( string $layout, array $subs ): array {
	$plan = SECTION_GROUPS[ $layout ] ?? null;

	if ( null === $plan ) {
		return $subs;
	}

	// Index by name. A layout with two fields of one name would collide, which
	// the model does not do — but take the first and leave the rest to the
	// leftovers rather than silently dropping one.
	$by_name = [];

	foreach ( $subs as $i => $sub ) {
		$name = $sub['name'] ?? '';

		if ( '' !== $name && ! isset( $by_name[ $name ] ) ) {
			$by_name[ $name ] = $i;
		}
	}

	$out   = [];
	$taken = [];
	$n     = 0;

	foreach ( $plan as $group ) {
		list( $title, $names ) = $group;

		$members = [];

		foreach ( $names as $name ) {
			if ( isset( $by_name[ $name ] ) && ! isset( $taken[ $by_name[ $name ] ] ) ) {
				$members[]                    = $subs[ $by_name[ $name ] ];
				$taken[ $by_name[ $name ] ] = true;
			}
		}

		// A group whose fields are all absent draws no empty accordion.
		if ( ! $members ) {
			continue;
		}

		$out[] = group_divider( $layout, $title, $n, 0 === $n );
		$out   = array_merge( $out, $members );
		++$n;
	}

	// Everything the plan did not name, in its original order. This is the
	// safety net: a field added to a layout later still appears.
	$rest = [];

	foreach ( $subs as $i => $sub ) {
		if ( ! isset( $taken[ $i ] ) ) {
			$rest[] = $sub;
		}
	}

	if ( $rest ) {
		$out[] = group_divider( $layout, SETTINGS_GROUP, $n, false );
		$out   = array_merge( $out, $rest );
	}

	return $out;
}

/**
 * Rewrite the Page sections field as wp-admin draws it.
 *
 * Guarded on the field key so this touches one field and nothing else, and
 * returns the field untouched the moment anything is not the shape expected —
 * a wrong guess here would be a broken edit screen on 20 live pages.
 */
function group_section_fields( $field ) {
	if ( ! is_array( $field ) || 'field_vs_blocks' !== ( $field['key'] ?? '' ) ) {
		return $field;
	}

	if ( empty( $field['layouts'] ) || ! is_array( $field['layouts'] ) ) {
		return $field;
	}

	foreach ( $field['layouts'] as $i => $layout ) {
		if ( ! is_array( $layout ) || empty( $layout['sub_fields'] ) || ! is_array( $layout['sub_fields'] ) ) {
			continue;
		}

		$field['layouts'][ $i ]['sub_fields'] = group_layout_fields(
			(string) ( $layout['name'] ?? '' ),
			$layout['sub_fields']
		);
	}

	return $field;
}
add_filter( 'acf/prepare_field', __NAMESPACE__ . '\\group_section_fields', 30 );

/**
 * ─── Repeater rows collapse, and each one says what it is ──────────────────
 *
 * Every repeater in the model renders each row fully expanded. On a section
 * with six cards that is six stacks of boxes; on /privacy-policy/ it is
 * nineteen numbered clauses open at once, and on /terms-conditions/
 * twenty-three — the two pages where an editor is most likely to be hunting
 * for one specific paragraph. Twenty-one of the twenty-four repeaters were
 * declared with no `collapsed` setting at all, so none of them could be
 * folded shut.
 *
 * Setting `collapsed` to a sub-field does two things: rows arrive closed, and
 * each closed row shows THAT sub-field's value as its title. So the legal
 * pages become a numbered contents list, and a card grid becomes a list of
 * card titles — the same idea as the section handles, one level down.
 *
 * The sub-field chosen is the one a person would use to find the row again:
 * the heading for a section, the question for an FAQ, the title for a card,
 * the plan name for a price. Applied on `acf/prepare_field` for the same
 * reason as the grouping above — it is a drawing concern, and cannot reach
 * the schema from here.
 *
 * The four `table`-layout repeaters (the photo list, the “On this page” menu,
 * the credential figures, the hero buttons) are left alone: a table row is
 * already one line, and collapsing it would hide the only thing it shows.
 */
const REPEATER_ROW_TITLE = [
	'field_vs_process_steps' => 'field_vs_step_title', // process_steps → title
	'field_vs_sections' => 'field_vs_section_heading', // sections → heading
	'field_vs_cards' => 'field_vs_card_title', // cards → title
	'field_vs_faqs' => 'field_vs_faq_q', // faqs → question
	'field_vs_blk_faq_items' => 'field_vs_blk_faq_q', // items → question
	'field_vs_blk_cards_cards' => 'field_vs_blk_cards_card_title', // cards → title
	'field_vs_blk_media_sub_cards' => 'field_vs_blk_media_sub_card_title', // sub_cards → title
	'field_vs_blk_steps_pre_cards' => 'field_vs_blk_steps_pre_card_heading', // pre_cards → heading
	'field_vs_blk_steps_steps' => 'field_vs_blk_steps_step_title', // steps → title
	'field_vs_blk_compare_cards' => 'field_vs_blk_compare_card_title', // tiers → title
	'field_vs_blk_compare_alt_cards' => 'field_vs_blk_compare_alt_card_title', // alt_cards → title
	'field_vs_blk_compare_glossary' => 'field_vs_blk_compare_glossary_row_title', // glossary → title
	'field_vs_blk_pricing_plans' => 'field_vs_blk_pricing_plan_name', // plans → name
	'field_vs_blk_stat_points' => 'field_vs_blk_stat_point_lead', // points → lead
	'field_vs_blk_cstats_stats' => 'field_vs_blk_cstats_stat_label', // stats → label
	'field_vs_blk_tech_cards' => 'field_vs_blk_tech_card_title', // tech_cards → title
	'field_vs_blk_svc_cards' => 'field_vs_blk_svc_card_title', // svc_cards → title
	'field_vs_blk_docs_bios' => 'field_vs_blk_docs_bio_heading', // bios → heading
	'field_vs_blk_cand_ledger' => 'field_vs_blk_cand_ledger_title', // ledger → title
];

/**
 * Give a repeater its row title. Returns the field untouched unless it is one
 * of the repeaters above and still has no `collapsed` of its own, so a setting
 * added in the content model later wins over this table rather than fighting
 * it.
 */
function collapse_repeater_rows( $field ) {
	if ( ! is_array( $field ) || 'repeater' !== ( $field['type'] ?? '' ) ) {
		return $field;
	}

	$sub = REPEATER_ROW_TITLE[ $field['key'] ?? '' ] ?? null;

	if ( null === $sub || ! empty( $field['collapsed'] ) ) {
		return $field;
	}

	$field['collapsed'] = $sub;

	// No stored preference means this editor has never folded this repeater,
	// so fold it for them once — see print_first_load_collapse() below.
	if ( '' === (string) acf_get_user_setting( 'collapsed_' . $field['key'], '' ) ) {
		first_load_collapse( $field['key'] );
	}

	return $field;
}
add_filter( 'acf/prepare_field', __NAMESPACE__ . '\\collapse_repeater_rows', 30 );

/**
 * ─── Rows arrive folded the first time ─────────────────────────────────────
 *
 * `collapsed` above gives every row a fold control and a title; it does not
 * fold anything. ACF decides that from a per-user preference, which a new
 * editor does not have — so their FIRST sight of /privacy-policy/ would still
 * be nineteen clauses open at once, which is the thing being fixed.
 *
 * So on a render where ACF holds no preference for one of these repeaters, a
 * few lines of script fold its rows. It only adds the class ACF's own toggle
 * adds and writes no preference of its own, so the moment the editor folds or
 * unfolds anything themselves, ACF stores that and this stops applying.
 *
 * Deliberately not done by clicking each toggle: on a nineteen-row page that
 * would fire nineteen of ACF's preference writes on page load.
 */
function first_load_collapse( ?string $key = null ): array {
	static $keys = [];

	if ( null !== $key && ! in_array( $key, $keys, true ) ) {
		$keys[] = $key;
	}

	return $keys;
}

function print_first_load_collapse(): void {
	$keys = first_load_collapse();

	if ( ! $keys ) {
		return;
	}

	?>
	<script>
	( function () {
		var keys = <?php echo wp_json_encode( $keys ); ?>;

		// querySelectorAll, not querySelector: a repeater nested in a section
		// layout is drawn once per row of that section, so there is rarely only
		// one of them on the page.
		function fold( root ) {
			keys.forEach( function ( key ) {
				( root || document )
					.querySelectorAll( '.acf-field[data-key="' + key + '"]' )
					.forEach( function ( field ) {
						field.querySelectorAll( '.acf-row:not(.acf-clone)' ).forEach( function ( row ) {
							row.classList.add( '-collapsed' );
						} );
					} );
			} );
		}

		if ( document.readyState === 'loading' ) {
			document.addEventListener( 'DOMContentLoaded', function () { fold(); } );
		} else {
			fold();
		}

		// ACF does not set up the fields inside a folded section row until that
		// row is opened, and setting them up rebuilds the rows — which drops the
		// class the pass above added. So fold again when ACF says a repeater is
		// ready, and once more after a section handle is clicked open.
		if ( window.acf && typeof acf.addAction === 'function' ) {
			acf.addAction( 'ready_field/type=repeater', function ( field ) {
				if ( field && field.$el ) { fold( field.$el[0].parentNode || document ); }
			} );
			acf.addAction( 'append_field/type=repeater', function () { fold(); } );
		}

		document.addEventListener( 'click', function ( e ) {
			if ( e.target.closest && e.target.closest( '.acf-fc-layout-handle' ) ) {
				setTimeout( function () { fold(); }, 60 );
			}
		}, true );
	} )();
	</script>
	<?php
}
add_action( 'admin_footer', __NAMESPACE__ . '\\print_first_load_collapse' );

/**
 * ─── Tabs a page does not use stop being drawn ─────────────────────────────
 *
 * Every page carries the same nine tabs, and almost no page uses all nine.
 * Counted across the 33 published pages: 126 of the 297 tab-instances hold no
 * data at all, and on /404-2/ eight of the nine are both empty and inert. The
 * orientation message has been telling editors this in prose — "a box on any
 * other tab saves, but does not change this page" — which is a sentence asking
 * them to remember a list. The tabs can simply not be there.
 *
 * THREE STATES, and the distinction is the whole safety of this:
 *
 *   live                     → untouched. This is where the page is edited.
 *   not live, holds nothing  → hidden. There is nothing to lose: no data, and
 *                              by liveTabs no effect on the page.
 *   not live, holds something → NOT hidden. Its label gains "(not used here)".
 *                              Hiding would make saved words unreachable, and
 *                              85 tab-instances are in this state — mostly the
 *                              legacy Section copy rows on pages that have
 *                              since moved to Page sections.
 *
 * PAGE SECTIONS IS NEVER HIDDEN, even where it is empty and not live. It is
 * the switch every remaining page will be migrated with, and its emptiness on
 * a template page is a deliberate state the orientation message explains. A
 * tab we ask the client to leave alone still has to be visible to us.
 *
 * Liveness comes from PAGES, which was built per page against the templates.
 * Data is read from post meta at render time, so a tab that gains content
 * reappears on the next page load without anything here changing.
 */

/** tab label → [ its own field key, the field keys that belong to it ]. */
const TAB_FIELDS = [
	'On this page'   => [ 'field_vs_toc_tab',      [ 'field_vs_toc_note', 'field_vs_toc_links' ] ],
	'Process'        => [ 'field_vs_process_tab',  [ 'field_vs_process_note', 'field_vs_process_steps' ] ],
	'Section copy'   => [ 'field_vs_sections_tab', [ 'field_vs_sections_note', 'field_vs_sections' ] ],
	'Images'         => [ 'field_vs_images_tab',   [ 'field_vs_images' ] ],
	'Cards & lists'  => [ 'field_vs_cards_tab',    [ 'field_vs_cards' ] ],
	'FAQ'            => [ 'field_vs_faq_tab',      [ 'field_vs_faqs' ] ],
	'Hero'           => [ 'field_vs_hero_tab',     [ 'field_vs_hero_intro', 'field_vs_page_hero' ] ],
	'Page sections'  => [ 'field_vs_blocks_tab',   [ 'field_vs_blocks_intro', 'field_vs_blocks' ] ],
	'Bottom of page' => [ 'field_vs_closing_tab',  [ 'field_vs_closing_intro', 'field_vs_page_closing' ] ],
];

/** The one tab that stays visible even when empty and inert — see the header. */
const TAB_NEVER_HIDDEN = 'Page sections';

/** The meta key a tab's content lives under: a repeater/flexible count, or a group prefix. */
const TAB_META = [
	'On this page'   => [ 'count', 'toc_links' ],
	'Process'        => [ 'count', 'process_steps' ],
	'Section copy'   => [ 'count', 'sections' ],
	'Images'         => [ 'count', 'images' ],
	'Cards & lists'  => [ 'count', 'cards' ],
	'FAQ'            => [ 'count', 'faqs' ],
	'Page sections'  => [ 'count', 'blocks' ],
	'Hero'           => [ 'prefix', 'hero_' ],
	'Bottom of page' => [ 'prefix', 'closing_' ],
];

/** Which tab a field key belongs to, or null for the fields outside every tab. */
function tab_owning( string $key ): ?string {
	foreach ( TAB_FIELDS as $tab => $pair ) {
		if ( $key === $pair[0] || in_array( $key, $pair[1], true ) ) {
			return $tab;
		}
	}

	return null;
}

/**
 * Whether this page has anything saved under a tab.
 *
 * Read from post meta rather than get_field() on purpose: this runs inside
 * `acf/prepare_field`, and asking ACF for a field's value from there is a way
 * to re-enter the same filter. A repeater stores its row count under its own
 * name; a group stores nothing under its name and one row per sub-field, so
 * those are found by prefix.
 */
function tab_has_data( int $post_id, string $tab ): bool {
	static $cache = [];

	if ( isset( $cache[ $post_id ][ $tab ] ) ) {
		return $cache[ $post_id ][ $tab ];
	}

	list( $how, $needle ) = TAB_META[ $tab ] ?? [ null, null ];
	$has = false;

	if ( 'count' === $how ) {
		$value = get_post_meta( $post_id, $needle, true );
		$has   = is_array( $value ) ? (bool) $value : ( '' !== (string) $value && '0' !== (string) $value );
	} elseif ( 'prefix' === $how ) {
		foreach ( get_post_meta( $post_id ) as $meta_key => $values ) {
			if ( 0 === strpos( $meta_key, $needle ) && '' !== trim( (string) ( $values[0] ?? '' ) ) ) {
				$has = true;
				break;
			}
		}
	}

	$cache[ $post_id ][ $tab ] = $has;

	return $has;
}

/**
 * Hide a tab this page does not use, or say so on its label.
 *
 * Runs at priority 5, before the guidance rewrites: a field this returns false
 * for is never drawn, so there is no point spending the later filters on it.
 */
function trim_dead_tabs( $field ) {
	if ( ! is_array( $field ) ) {
		return $field;
	}

	$page = current_page();

	if ( null === $page ) {
		return $field;
	}

	$tab = tab_owning( $field['key'] ?? '' );

	if ( null === $tab || tab_is_live( $page, $tab ) || TAB_NEVER_HIDDEN === $tab ) {
		return $field;
	}

	$post_id = isset( $_GET['post'] ) ? (int) $_GET['post'] : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

	if ( ! $post_id && isset( $GLOBALS['post']->ID ) ) {
		$post_id = (int) $GLOBALS['post']->ID;
	}

	if ( $post_id && ! tab_has_data( $post_id, $tab ) ) {
		return false;
	}

	// Holds words, so it stays reachable — but says what it is.
	if ( 'tab' === ( $field['type'] ?? '' ) ) {
		$field['label'] = $tab . ' (not used here)';
	}

	return $field;
}
add_filter( 'acf/prepare_field', __NAMESPACE__ . '\\trim_dead_tabs', 5 );

/**
 * ─── "Start here" on the dashboard ─────────────────────────────────────────
 *
 * The first screen after logging in is the WordPress dashboard, which says
 * nothing about this site: At a Glance, Activity, Quick Draft, and whatever the
 * host advertises. Someone told "the hours changed" has to already know that
 * hours live under Practice Settings rather than under Settings, which is a
 * different menu three items further down.
 *
 * So a panel that answers "where do I go for…" in the words a receptionist
 * would use, pinned to the top of the dashboard.
 *
 * NOT gated on role, unlike vs-admin.php. That file curates the menu for
 * non-administrators and exempts administrators deliberately — but this site
 * has exactly one account and it is an administrator, so anything gated that
 * way is inert here. A signpost is harmless to a developer and the only help a
 * client gets, so it is shown to everyone.
 */
function dashboard_signpost(): void {
	$rows = [
		[ 'The words and photos on a page', 'edit.php?post_type=page', 'Pages',
			'Open the page, then read the note at the top — it says which tabs change that page.' ],
		[ 'Phone, address, opening hours', 'admin.php?page=vs-practice-settings', 'Practice Settings',
			'Changed in one place and updated everywhere on the site.' ],
		[ 'Patient reviews', 'edit.php?post_type=vs_testimonial', 'Testimonials',
			'One review per entry; the newest show first.' ],
		[ 'Blog posts', 'edit.php', 'Posts',
			'Write the article in the big box; the Featured image is the photo at the top.' ],
		[ 'The menus across the top of the site', 'nav-menus.php', 'Appearance → Menus',
			'Drag an item to move it. Tell us before adding a brand-new page to a menu.' ],
	];

	echo '<p style="margin:0 0 14px">Not sure where something lives? Start here.</p>';

	// A list, not a two-column table: this widget sits in a dashboard column
	// roughly 250px wide, where two columns squeeze every line to three words.
	echo '<ul style="margin:0">';

	foreach ( $rows as $row ) {
		list( $need, $url, $label, $note ) = $row;

		printf(
			'<li style="margin:0 0 14px;padding:0 0 14px;border-bottom:1px solid #f0f0f1">'
				. '<strong>%s</strong><br>'
				. '<a href="%s">%s</a> &nbsp;<span style="color:#646970">%s</span></li>',
			esc_html( $need ),
			esc_url( admin_url( $url ) ),
			esc_html( $label ),
			esc_html( $note )
		);
	}

	echo '</ul>';
	echo '<p style="margin:12px 0 0;color:#646970">Everything you save here appears on the website a '
		. 'few minutes later, once it rebuilds. If something has not changed after that, tell us.</p>';
}

function add_dashboard_signpost(): void {
	wp_add_dashboard_widget(
		'vs_start_here',
		'Start here — where to change what',
		__NAMESPACE__ . '\\dashboard_signpost'
	);

	// Pin it to the top of the left column; wp_add_dashboard_widget() appends,
	// which on a host that adds its own panels buries it below them.
	global $wp_meta_boxes;

	if ( ! isset( $wp_meta_boxes['dashboard']['normal']['core'] ) ) {
		return;
	}

	$column = $wp_meta_boxes['dashboard']['normal']['core'];

	if ( ! isset( $column['vs_start_here'] ) ) {
		return;
	}

	$ours = [ 'vs_start_here' => $column['vs_start_here'] ];
	unset( $column['vs_start_here'] );

	$wp_meta_boxes['dashboard']['normal']['core'] = $ours + $column;
}
add_action( 'wp_dashboard_setup', __NAMESPACE__ . '\\add_dashboard_signpost' );

/**
 * ─── What the bottom of each page says today ───────────────────────────────
 *
 * The <em>Bottom of page</em> boxes are empty on every page, and the tab's own
 * note explains why: the invite headline is a switch, and while it is blank the
 * page keeps its built-in wording. Correct, and useless to someone who wants to
 * change a word — they cannot see the words. To edit "Free aligners
 * consultation." they first have to go and find it on the site.
 *
 * So the tab now prints what that page says today, beneath the explanation.
 * Copy a line into the box, change the bit you want, done.
 *
 * READ OFF THE BUILT SITE, NOT THE TEMPLATES. The wording lives in JSX
 * fallbacks inside 23 .astro files, and several are not plain text — they carry
 * an inline link or a {phoneLabel} expression. Scraping the rendered HTML of
 * dist/ instead gives exactly what a visitor reads, link text and phone number
 * included, for all 30 pages that have a closing band rather than the 20 the
 * template scrape could reach.
 *
 * IT IS A SNAPSHOT, and says so on screen. Nothing reads it at build time; if a
 * template's wording is edited in code, this goes stale until it is re-scraped.
 * That is the trade for showing anything at all, and the alternative — writing
 * these 70 values into the fields for real — was measured and is NOT free:
 * Astro renders a JSX fallback with its source newlines and indentation baked
 * into the HTML, so a clean one-line CMS value changes the bytes of ~20 live
 * routes. Identical words, different whitespace. Worth doing deliberately, not
 * as a side effect of making the text visible.
 */
const CLOSING_TODAY = [
	78 => [ // /
		'eyebrow' => 'Free virtual consultation',
		'headline' => 'Free virtual consultation.',
		'body' => 'Taking the first step toward your dream smile has never been easier. Nothing to schedule — Dr. Richardson personally records a custom video response for every consultation request.',
	],
	79 => [ // /about-us/
		'eyebrow' => 'Free virtual consultation',
		'headline' => 'Begin your Vivid Smile.',
		'body' => 'Whether it\'s a smile makeover, a second opinion, or just a question — Dr. Richardson personally records a custom video reply for every consultation request.',
	],
	80 => [ // /contact/
		'note' => 'Call our front desk directly — Mon–Wed 8a–5p, Thu–Fri 8a–1p.',
	],
	81 => [ // /cosmetic-dentistry-lp/
		'eyebrow' => 'Reserve your free consult',
		'headline' => 'Book your free smile consultation.',
		'body' => 'Dr. Richardson takes a limited number of new cosmetic cases each month. Fill out the form below — choose virtual or in-office — and reserve your spot. He personally reviews every request.',
	],
	82 => [ // /cosmetic-dentistry/
		'eyebrow' => 'Free cosmetic dentistry consultation',
		'headline' => 'Free cosmetic dentistry consultation.',
		'body' => 'Taking the first step toward your smile has never been easier. Nothing to schedule — Dr. Richardson personally records a custom video response for every consultation request. Photos in, video out.',
		'note' => 'Vivid Smiles is located in Parker, CO and has earned 300+ five-star Google reviews from patients who wanted natural, lasting cosmetic results and got them.',
	],
	83 => [ // /dental-membership-plan/
		'note' => 'Join today and use the benefits this afternoon. No paperwork, no waiting period, no surprises.',
	],
	84 => [ // /emergency-dentistry/
		'note' => 'Vivid Smiles Dentistry in Parker offers same-day emergency dental care backed by CBCT 3D imaging, in-house milling, and a dual-doctor team with 600+ hours of advanced surgical training.',
	],
	85 => [ // /general-dentistry/
		'eyebrow' => 'Free general dentistry consultation',
		'headline' => 'Free general dentistry consultation.',
		'body' => 'New to the practice or returning after years away? Send a few photos and we\'ll record a custom video response with findings, next steps, and a recommended visit cadence. Photos in, video out — no commitments.',
		'note' => 'Vivid Smiles Dentistry in Parker offers comprehensive general care from a dual-doctor team backed by advanced diagnostic technology and 300+ five-star Google reviews.',
	],
	86 => [ // /general-lp/
		'eyebrow' => 'New patients welcome',
		'headline' => 'Request your appointment.',
		'body' => 'New to the practice or returning after years away? Fill out the form below — choose virtual or in-office — and the Vivid Smiles team will be in touch. New patients and same-day emergencies are always welcome.',
	],
	87 => [ // /implant-dentistry/
		'eyebrow' => 'Free dental implant consultation',
		'headline' => 'Free dental implant consultation.',
		'body' => 'Considering implants? Send a few photos and Dr. Richardson personally records a custom video response with candidacy assessment, treatment options, and next steps. Photos in, video out — no commitments.',
		'note' => 'Vivid Smiles Dentistry in Parker offers robotic-guided placement and in-house surgical + restorative care, backed by 600-plus hours of advanced training and 300+ five-star Google reviews.',
	],
	88 => [ // /new-patients/
		'eyebrow' => 'Ready when you are',
		'headline' => 'Let\'s get you scheduled.',
		'body' => 'Book online, give us a call, or send a quick virtual consult with a photo or two — whatever\'s easiest. We\'ll take it from there.',
	],
	89 => [ // /our-office/
		'eyebrow' => 'Plan your first visit',
		'headline' => 'Plan your first visit.',
		'body' => 'Book online, give us a call, or send a quick virtual consult with a photo or two — whatever\'s easiest. Our team will take it from there.',
	],
	90 => [ // /patient-testimonials/
		'eyebrow' => 'Free virtual consultation',
		'headline' => 'Ready to write your story?',
		'body' => 'Every transformation on this page started the same way — a few photos, a few questions, and a custom video back from Dr. Richardson. Skip the office visit for the first conversation and see what\'s possible for your smile.',
	],
	92 => [ // /referral-program/
		'note' => 'Sharing a smile has never been easier. If you\'d like to refer a friend or have a question about the program, give us a call at (303) 841-5313 — we\'ll take it from there.',
	],
	93 => [ // /services/
		'eyebrow' => 'Free virtual consultation',
		'headline' => 'Free virtual consultation.',
		'body' => 'Taking the first step toward your smile has never been easier. Nothing to schedule — Dr. Richardson personally records a custom video response for every consultation request. Photos in, video out.',
	],
	94 => [ // /smile-gallery/
		'eyebrow' => 'Begin Your Case',
		'headline' => 'Your smile could be next.',
		'body' => 'Every smile in the gallery above started with one conversation. Skip the office visit and Dr. Richardson personally records a custom video reviewing your smile, the changes you\'re imagining, and what\'s possible, all from photos you submit below.',
	],
	96 => [ // /thank-you/
		'note' => 'Call our front desk and we\'ll get you scheduled — same-day appointments are often available.',
	],
	97 => [ // /veneers-lp/
		'eyebrow' => 'Reserve your free consult',
		'headline' => 'Book your free veneer consultation.',
		'body' => 'Dr. Richardson takes a limited number of new veneer cases each month. Fill out the form below — choose virtual or in-office — and reserve your spot. He personally reviews every request.',
	],
	98 => [ // /cosmetic-dentistry/clear-aligners/
		'eyebrow' => 'Free aligners consultation',
		'headline' => 'Free aligners consultation.',
		'body' => 'Wondering whether Spark aligners are right for your smile? Skip the office visit — Dr. Richardson personally records a custom video previewing your alignment options, your projected timeline, and what to expect from candidacy through the final tray, all from a few photos you submit below.',
		'note' => '17167 Cedar Gulch Pkwy Ste 102, Parker, CO 80134. Patients welcome locally and from out of state.',
	],
	99 => [ // /cosmetic-dentistry/full-mouth-rehabilitation/
		'eyebrow' => 'Free full-mouth rehab consultation',
		'headline' => 'Free full-mouth rehab consultation.',
		'body' => 'Curious about full-mouth rehabilitation? Skip the office visit — Dr. Richardson personally records a custom video reviewing your situation, the path that fits your case, and what\'s possible with implants, cosmetic work, or a hybrid plan, all from photos you submit below.',
		'note' => 'Vivid Smiles Dentistry in Parker, Colorado, offers the specialized training, technology, and in-house coordination that full-mouth cases require.',
	],
	100 => [ // /cosmetic-dentistry/gum-contouring/
		'eyebrow' => 'Free gum contouring consultation',
		'headline' => 'Free gum contouring consultation.',
		'body' => 'Gum contouring is one of the most efficient cosmetic procedures available: immediate results, minimal recovery, and a lasting change to the way your smile looks. If you have ever wondered whether your gum line is affecting the way your teeth appear, a consultation with Dr. Richardson will give you a clear answer and a preview of what is possible before any commitment is made.',
		'note' => 'Free cosmetic consultations at 17167 Cedar Gulch Pkwy, Suite 102, Parker, CO 80134.',
	],
	101 => [ // /cosmetic-dentistry/porcelain-veneers/
		'eyebrow' => 'Free veneers consultation',
		'headline' => 'Free veneers consultation.',
		'body' => 'Curious about your veneer options? Skip the office visit — Dr. Richardson personally records a custom video reviewing your smile, the changes you\'re imagining, and what\'s possible with no-prep or minimal-prep porcelain veneers, all from photos you submit below.',
		'note' => 'Patients welcome locally and from out of state.',
	],
	102 => [ // /cosmetic-dentistry/smile-makeover/
		'eyebrow' => 'Free smile makeover consultation',
		'headline' => 'Free smile makeover consultation.',
		'body' => 'If you have spent years wanting to fix your smile, the next step is a consultation, not a commitment. At Vivid Smiles Dentistry, Dr. Richardson starts every smile makeover with a comprehensive analysis and a digital preview so you can see exactly what your results will look like before any treatment begins.',
		'note' => 'Vivid Smiles is located in Parker, CO and has earned 300+ five-star Google reviews from patients who wanted natural, lasting results and got them.',
	],
	103 => [ // /cosmetic-dentistry/teeth-whitening/
		'eyebrow' => 'Free whitening consultation',
		'headline' => 'Free whitening consultation.',
		'body' => 'Getting a noticeably whiter smile in Parker starts with a consultation built around your specific staining type and shade goals. KOR\'s treatment levels cover nearly every case, and digital smile design gives you a preview of results before treatment begins.',
		'note' => 'Schedule your consultation and take the first step toward lasting whitening results.',
	],
	104 => [ // /implant-dentistry/all-on-4-single-arch/
		'eyebrow' => 'Free All-on-4 consultation',
		'headline' => 'Free All-on-4 consultation.',
		'body' => 'Vivid Smiles Dentistry in Parker, CO, brings Dr. Richardson\'s 600+ hours of surgical training, Implant Pathway credentials, and robotic technology to deliver outcomes grounded in precision planning. Schedule your free consultation below or call (303) 841-5313 to learn which arch treatment suits you.',
		'note' => 'Dr. Bryce Richardson combines robotic precision with 600-plus hours of advanced surgical training to deliver All-on-4 outcomes built to last decades.',
	],
	105 => [ // /implant-dentistry/bone-grafting/
		'eyebrow' => 'Free bone grafting consultation',
		'headline' => 'Free bone grafting consultation.',
		'body' => 'Vivid Smiles Dentistry serves patients across Parker, Douglas County, and the surrounding communities. Schedule a free consultation online or call (303) 841-5313 to find out whether bone grafting is the right next step for you.',
		'note' => 'Dr. Bryce Richardson combines CBCT-guided planning with 600-plus hours of advanced surgical training to deliver bone grafting outcomes built to support implants for the long term.',
	],
	106 => [ // /implant-dentistry/full-mouth-dental-implants/
		'eyebrow' => 'Free full mouth implant consultation',
		'headline' => 'Free full mouth implant consultation.',
		'body' => 'Vivid Smiles Dentistry in Parker, CO, brings Dr. Richardson\'s 600+ hours of surgical training, Implant Pathway credentials, and robotic technology to deliver outcomes grounded in precision planning. Schedule your free consultation below or call (303) 841-5313 to learn whether full-mouth implants are the right path for you.',
		'note' => 'Dr. Richardson\'s staff training at Implant Pathway, robotic placement technology, and in-house lab capabilities give Parker-area clients access to our full range of dental services at a level found only at specialized surgical centers.',
	],
	107 => [ // /implant-dentistry/single-tooth-dental-implants/
		'eyebrow' => 'Free single tooth implant consultation',
		'headline' => 'Free single tooth implant consultation.',
		'body' => 'Vivid Smiles Dentistry in Parker, CO, manages the complete single-tooth implant process under one roof, without specialist referrals. Call (303) 841-5313 or book online to schedule your consultation with Dr. Richardson.',
		'note' => 'Dr. Bryce Richardson pairs robotic precision with 600-plus hours of advanced surgical training to deliver single tooth implant outcomes built to last decades.',
	],
	108 => [ // /implant-dentistry/sinus-lift/
		'eyebrow' => 'Free sinus lift consultation',
		'headline' => 'Free sinus lift consultation.',
		'body' => 'Curious whether a sinus lift is the right next step toward upper-jaw implants? Skip the office visit — Dr. Richardson personally records a custom video reviewing your case, your candidacy, and what\'s possible at Vivid Smiles Dentistry, all from photos you submit below.',
		'note' => 'Vivid Smiles Dentistry in Parker, CO, pairs Dr. Richardson\'s surgical training with CBCT imaging and robotic implant placement. Call (303) 841-5313 or book a free consultation to start with a 3D scan and a personalized plan.',
	],
	346 => [ // /blog/
		'eyebrow' => 'Ready When You Are',
		'headline' => 'Reading is one step. Talking is the next.',
		'body' => 'If something you read here applies to your smile, submit a few photos and Dr. Richardson will record a personalized video reply — no office visit required to get started.',
	],
];

/**
 * The "here is what it says now" block for the Bottom of page tab.
 */
function closing_today( int $post_id ): string {
	$rec = CLOSING_TODAY[ $post_id ] ?? null;

	if ( null === $rec ) {
		return '';
	}

	$labels = [
		'eyebrow'  => 'Small line',
		'headline' => 'Invite headline',
		'body'     => 'Invite paragraph',
		'note'     => 'Booking strip sentence',
	];

	$out = "\n<strong>What the bottom of this page says today</strong> — copy a line into the "
		. 'box above it and change what you want. (A snapshot taken from the live site; if it '
		. 'looks out of date, tell us.)';

	foreach ( $labels as $key => $label ) {
		if ( empty( $rec[ $key ] ) ) {
			continue;
		}

		$out .= "\n<em>" . esc_html( $label ) . ':</em> ' . esc_html( $rec[ $key ] );
	}

	return $out;
}

/**
 * ─── Each photo row says where that photo goes ─────────────────────────────
 *
 * The Images tab is a table whose first column is a code — `heroTeam`,
 * `imgWhitening`, `imgDrBryce`. What each code means is printed in a guide
 * ABOVE the table, which works until the table is long: /about-us/ has 25 rows,
 * so swapping the fourth photo means scrolling up, finding its line, and
 * scrolling back with it held in your head.
 *
 * The guide already knows, per page, which slot draws what. This puts that
 * sentence on the row itself, beside the code, so the lookup disappears.
 *
 * Presentation only, and done in the browser: it writes into the rendered table
 * and touches no field, no value and no filter. A row whose code is not in the
 * map — one added by hand since the map was written — simply gets nothing, and
 * the guide above is still there.
 */
function print_image_row_hints(): void {
	$page = current_page();

	if ( null === $page || empty( $page['images'] ) ) {
		return;
	}

	$map = [];

	foreach ( $page['images'] as $row ) {
		$slot = (string) ( $row['slot'] ?? '' );

		if ( '' === $slot ) {
			continue;
		}

		list( $state, $target ) = parse_status( (string) ( $row['status'] ?? 'live' ) );

		$where = rtrim( trim( (string) ( $row['where'] ?? '' ) ), '.' );

		if ( 'moved' === $state ) {
			$where .= ' — changed in Page sections now, in the section “' . $target . '”';
		} elseif ( 'dead' === $state ) {
			$where .= ' — part of the design now; this row changes nothing';
		}

		$map[ $slot ] = $where;
	}

	if ( ! $map ) {
		return;
	}

	?>
	<script>
	( function () {
		var where = <?php echo wp_json_encode( $map ); ?>;
		function label() {
			var field = document.querySelector( '.acf-field[data-key="field_vs_images"]' );
			if ( ! field ) { return; }
			field.querySelectorAll( '.acf-row:not(.acf-clone)' ).forEach( function ( row ) {
				var cell = row.querySelector( '.acf-field[data-name="slot"]' );
				if ( ! cell || cell.querySelector( '.vs-where' ) ) { return; }
				var input = cell.querySelector( 'input' );
				var text = input && where[ input.value ];
				if ( ! text ) { return; }
				var note = document.createElement( 'span' );
				note.className = 'vs-where';
				note.style.cssText = 'display:block;margin-top:4px;color:#646970;font-size:12px;line-height:1.4';
				note.textContent = text;
				cell.appendChild( note );
			} );
		}
		if ( document.readyState === 'loading' ) {
			document.addEventListener( 'DOMContentLoaded', label );
		} else {
			label();
		}
		// The Images tab is not the open one on most pages, and ACF only lays a
		// hidden tab's table out when it is first shown; re-run on any click so
		// rows that were zero-height at load still get labelled.
		document.addEventListener( 'click', function () { setTimeout( label, 80 ); }, true );
	} )();
	</script>
	<?php
}
add_action( 'admin_footer', __NAMESPACE__ . '\\print_image_row_hints' );
