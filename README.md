# README #

### What is this repository for? ###

* Import Questionmark QML files into the Moodle question bank

### Installation ###

Simply download the files as a .zip, upload to the Plugin Installation Wizard (Administration->Plugins->Install Plugins) and follow the instructions.

**Manual Install**
To install this plugin copy the qml folder into the "question->format" folder and update the database.
You can do this by going to "Site administration->Notifications" and clicking upgrade database.

### How to use ###

The plugin does not require any specific setup to use. Simply select Question Bank->Import from the Administration menu and under the File format options you should see "Questionmark QML Importer".
Select this option as the file type when trying to import QML files into the Moodle question bank.

If you try to import a file that contains unsupported question types, the import will fail unless to tell Moodle not to halt on errors. To do this, while on the Question Bank Import page, click on the
"General" drop down item and change "Stop on error" to "No". All supported questions will be imported and the unsupported questions will be ignored.

### Limitations ###

Currently many of the question attributes are set to default values. This means that when importing questions, specific question and/or answer feedback will not be imported and instead will be set to a generic response. The same goes with other question attributes e.g. units in numeric questions.
Any questions that contain an embedded image in the question text may not display unless the image is linked to via a URL that is accessible via the internet.

### Supported Questionmark question types ###

* Fill In The Blanks (FIB) *
* Multiple Response (MR)
* Multiple Choice (MC)
* True or False (TF)
* Essay Question (ESSAY)
* Text Match (TM)
* Numeric Question (NUM)
* Select/Select-a-blank (SEL)
* Matching Questions (MATCH)

*FIB questions will either be a short answer question or they will be converted into a Embedded Answers (Cloze) question type.
### Other question types (Unsupported) ###
* Hotspot (HOT)
* Ranking: Rank in Order (RANK)
* Likert Scale (LKS)