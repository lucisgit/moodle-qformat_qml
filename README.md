# README #

### What is this repository for? ###

* Import Questionmark QML files into the Moodle question bank

### Supported Questionmark question types ###

* Fill In The Blanks (FIB) *
* Multiple Response (MR)
* Multiple Choice (MC)

### Potential issues ###
*FIB questions have been converted to moodle shortanswer questions, this isn't ideal as the FIB questions can have multiple answers in one question, but shortanswers only support one answer.

Example FIB Question

(Question) **{blank}** is a very **{blank}** day.

(Answer) **Today** is a very **sunny** day.

This would mean that the answer to this question after it has been imported to Moodle would become a string which matches (case insensitive): "Today,sunny". 

If the user only knew part of the answer e,g, "Today," then they would get 0 marks for this question.

A good fix to this would be to create an equivalent FIB question type for Moodle.